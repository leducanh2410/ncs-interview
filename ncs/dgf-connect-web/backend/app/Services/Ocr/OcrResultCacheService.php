<?php

namespace App\Services\Ocr;

use App\Actions\Ocr\EnrichOcrFieldsWithPostalCodeAction;
use App\Actions\Ocr\ValidateOcrRequiredFieldsAction;
use App\Enums\OcrResultStatus;
use App\Exceptions\OcrRequiredFieldsMissingException;
use App\Exceptions\OcrResultInvalidatedException;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Services\External\OpenRouterOcrService;
use App\Support\Ocr\OcrFieldNormalizer;
use Throwable;

/**
 * Persists OCR runs and serves completed results as a cache so the same
 * set of images is never sent to the LLM twice for the same primary model.
 */
class OcrResultCacheService
{
    public const SOURCE_EMPLOYEE = 'employee';

    public const SOURCE_ADMIN = 'admin';

    // Bump whenever the extraction schema/prompt gains or changes fields, so results cached
    // before the change (missing the new fields) are not served as a cache hit.
    private const SCHEMA_VERSION = 4;

    public function __construct(
        private readonly OpenRouterOcrService $ocrService,
        private readonly OcrFieldNormalizer $normalizer,
        private readonly ValidateOcrRequiredFieldsAction $validateRequiredFields,
        private readonly OcrImagePreparer $imagePreparer,
        private readonly EnrichOcrFieldsWithPostalCodeAction $enrichWithPostalCode
    ) {}

    /**
     * @param int[] $itemIds
     */
    public function cacheKey(string $group, array $itemIds): string
    {
        $itemIds = $this->sortedIds($itemIds);

        return hash(
            'sha256',
            self::SCHEMA_VERSION . '|' . $this->ocrService->primaryModel() . '|' . $group . '|' . implode(',', $itemIds)
        );
    }

    /**
     * @param int[] $itemIds
     */
    public function findCached(string $group, array $itemIds): ?UserPaperOcrResult
    {
        return UserPaperOcrResult::query()
            ->where('cache_key', $this->cacheKey($group, $itemIds))
            ->where('status', OcrResultStatus::Completed->value)
            ->whereNull('invalidated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Stop serving cached results that were computed from the given item's image,
     * e.g. after the stored file has been modified (rotation fixed).
     *
     * @return int number of results invalidated
     */
    public function invalidateForItem(int $itemId): int
    {
        return UserPaperOcrResult::query()
            ->whereIn('status', [
                OcrResultStatus::Processing->value,
                OcrResultStatus::Completed->value,
                OcrResultStatus::Failed->value,
            ])
            ->whereNull('invalidated_at')
            ->whereJsonContains('item_ids', $itemId)
            ->update(['invalidated_at' => now()]);
    }

    /**
     * @param int[] $itemIds
     */
    public function createPending(
        string $group,
        array $itemIds,
        string $source,
        ?int $requestedByUserId
    ): UserPaperOcrResult {
        return UserPaperOcrResult::query()->create([
            'ocr_group' => $group,
            'item_ids' => $this->sortedIds($itemIds),
            'cache_key' => $this->cacheKey($group, $itemIds),
            'model' => $this->ocrService->primaryModel(),
            'status' => OcrResultStatus::Processing->value,
            'source' => $source,
            'requested_by_user_id' => $requestedByUserId,
        ]);
    }

    /**
     * Run the LLM extraction and persist the outcome on the result row.
     * Failures are persisted before being rethrown so pollers can see them.
     *
     * @param iterable<UserPaperItem> $items in send order (front side before back side)
     * @param bool $retryWithFallback see OpenRouterOcrService::extract(); only for queued paths
     * @param bool $enforceRequiredFields fail the run when required fields are unreadable; only
     *                                    for the employee flow — the admin path may legitimately
     *                                    extract partial image sets (e.g. the back side alone)
     */
    public function run(
        UserPaperOcrResult $result,
        iterable $items,
        bool $retryWithFallback = false,
        bool $enforceRequiredFields = false
    ): UserPaperOcrResult {
        try {
            $result->refresh();
            if ($result->invalidated_at !== null) {
                throw new OcrResultInvalidatedException();
            }

            $images = [];
            foreach ($items as $item) {
                $images[] = ($this->imagePreparer)($item->file_path);
            }

            $extracted = $this->ocrService->extract($result->ocr_group, $images, $retryWithFallback);
            $normalized = $this->normalizer->normalize($result->ocr_group, $extracted['fields']);
            $normalized = ($this->enrichWithPostalCode)($result->ocr_group, $normalized);
            if ($enforceRequiredFields) {
                ($this->validateRequiredFields)($result->ocr_group, $normalized['fields']);
            }

            $completed = UserPaperOcrResult::query()
                ->whereKey($result->id)
                ->whereNull('invalidated_at')
                ->update([
                    'status' => OcrResultStatus::Completed->value,
                    'model' => $extracted['model'],
                    'fields' => $normalized['fields'],
                    'warnings' => $normalized['warnings'],
                    'error' => null,
                    'completed_at' => now(),
                ]);
            if ($completed === 0) {
                throw new OcrResultInvalidatedException();
            }

            $result->refresh();
            if ($result->invalidated_at !== null) {
                throw new OcrResultInvalidatedException();
            }
        } catch (OcrResultInvalidatedException $e) {
            throw $e;
        } catch (OcrRequiredFieldsMissingException $e) {
            $result->update([
                'status' => OcrResultStatus::Failed->value,
                'fields' => $normalized['fields'],
                'warnings' => $normalized['warnings'],
                'error' => OcrRequiredFieldsMissingException::ERROR_CODE,
                'completed_at' => now(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            $result->update([
                'status' => OcrResultStatus::Failed->value,
                'error' => mb_substr($e->getMessage(), 0, 100),
                'completed_at' => now(),
            ]);

            throw $e;
        }

        return $result;
    }

    /**
     * @param int[] $itemIds
     * @return int[]
     */
    private function sortedIds(array $itemIds): array
    {
        $itemIds = array_map('intval', array_values($itemIds));
        sort($itemIds);

        return $itemIds;
    }
}
