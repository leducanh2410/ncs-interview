<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperType;
use App\Models\User;
use App\Models\UserPaperItem;
use App\Services\External\OpenRouterOcrService;
use App\Services\Ocr\OcrResultCacheService;
use App\Support\Papers\CurrentPaperItemSelector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractPapersOcrAction
{
    public const ERROR_OCR_PROVIDER = 'OCR_PROVIDER_ERROR';

    // Order matters: within a group the front side must be sent before the back side
    private const TYPE_GROUPS = [
        UserPaperType::ForeignerCardFront->value => OpenRouterOcrService::GROUP_RESIDENCE_CARD,
        UserPaperType::ForeignerCardBack->value => OpenRouterOcrService::GROUP_RESIDENCE_CARD,
        UserPaperType::ForeignerCardScan->value => OpenRouterOcrService::GROUP_RESIDENCE_CARD,
        UserPaperType::Passport->value => OpenRouterOcrService::GROUP_PASSPORT,
        UserPaperType::StudentCardFront->value => OpenRouterOcrService::GROUP_STUDENT_CARD,
        UserPaperType::StudentCardBack->value => OpenRouterOcrService::GROUP_STUDENT_CARD,
    ];

    public function __construct(
        private readonly OcrResultCacheService $cacheService,
        private readonly CurrentPaperItemSelector $currentItems
    ) {}

    public function __invoke(User $user, array $types, bool $force = false): ActionResult
    {
        $items = $this->eligibleItemsByType($user, $types);

        $missingTypes = array_values(array_diff($types, $items->keys()->all()));
        if (!empty($missingTypes)) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: [
                    'message' => 'Some requested paper types have no usable image',
                    'missing_types' => $missingTypes,
                ]
            );
        }

        $groups = [];
        foreach (self::TYPE_GROUPS as $type => $group) {
            if (in_array($type, $types, true)) {
                $groups[$group][] = $type;
            }
        }

        $results = [];
        foreach ($groups as $group => $groupTypes) {
            $groupItems = array_map(fn(string $type) => $items[$type], $groupTypes);
            $itemIds = array_map(fn(UserPaperItem $item) => $item->id, $groupItems);

            $cached = $force ? null : $this->cacheService->findCached($group, $itemIds);
            if ($cached) {
                $results[] = $this->formatResult($group, $groupTypes, $cached, fromCache: true);

                continue;
            }

            $resultRow = $this->cacheService->createPending(
                $group,
                $itemIds,
                OcrResultCacheService::SOURCE_ADMIN,
                Auth::id()
            );

            try {
                $this->cacheService->run($resultRow, $groupItems);
                $results[] = $this->formatResult($group, $groupTypes, $resultRow, fromCache: false);
            } catch (Throwable $e) {
                Log::error('Paper OCR extraction failed', [
                    'user_id' => $user->id,
                    'group' => $group,
                    'types' => $groupTypes,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'group' => $group,
                    'types' => $groupTypes,
                    'status' => 'failed',
                    'error' => self::ERROR_OCR_PROVIDER,
                    'fields' => null,
                    'warnings' => [],
                    'ocr_result_id' => $resultRow->id,
                    'from_cache' => false,
                    'cached_at' => null,
                ];
            }
        }

        return new ActionResult(data: ['results' => $results]);
    }

    /**
     * Newest usable image per requested type: a still-pending upload wins (so admins can
     * review documents from the step flow before approving them), otherwise the newest
     * approved item in the current folder.
     *
     * @return Collection<string, UserPaperItem> keyed by type
     */
    private function eligibleItemsByType(User $user, array $types): Collection
    {
        $candidates = UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->whereIn('type', $types)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get();

        return ($this->currentItems)($candidates, $types);
    }

    private function formatResult(string $group, array $groupTypes, $resultRow, bool $fromCache): array
    {
        $resultRow->refresh();
        if ($resultRow->invalidated_at !== null) {
            return [
                'group' => $group,
                'types' => $groupTypes,
                'status' => 'failed',
                'error' => self::ERROR_OCR_PROVIDER,
                'fields' => null,
                'warnings' => [],
                'ocr_result_id' => $resultRow->id,
                'from_cache' => false,
                'cached_at' => null,
            ];
        }

        return [
            'group' => $group,
            'types' => $groupTypes,
            'status' => 'ok',
            'error' => null,
            'fields' => $resultRow->fields,
            'warnings' => $resultRow->warnings ?? [],
            'ocr_result_id' => $resultRow->id,
            'from_cache' => $fromCache,
            'cached_at' => $resultRow->completed_at,
        ];
    }
}
