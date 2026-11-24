<?php

namespace App\Actions\User\Document;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Helpers\ImageHelper;
use App\Jobs\ExtractDocumentStepOcrJob;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperOcrResult;
use App\Models\UserPaperStep;
use App\Services\Ocr\OcrResultCacheService;
use App\Services\User\DocumentStepService;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadDocumentStepAction
{
    public function __construct(
        private readonly DocumentStepService $stepService,
        private readonly OcrResultCacheService $cacheService
    ) {}

    /**
     * @param array<string, mixed> $files uploaded files keyed by paper type (passport holds an array)
     */
    public function __invoke(User $user, UserPaperStepKey $step, array $files): ActionResult
    {
        /** @var UserPaper $userPaper */
        $userPaper = $user->paper()->firstOrCreate(['user_id' => $user->id]);

        $storedPaths = [];
        try {
            $stepRow = $this->storeUpload($user, $userPaper, $step, $files, $storedPaths);
        } catch (ImageException) {
            Storage::delete($storedPaths);

            return new ActionResult(
                error: ErrorCode::UserPaperFileUnreadable,
                status: 422,
                data: ['message' => 'One of the images could not be read; take or choose the photo again']
            );
        } catch (Throwable $e) {
            // The transaction rolled back; the files written for it must not stay behind
            Storage::delete($storedPaths);

            throw $e;
        }

        // A step with an in-flight submission is locked until the admin decides on it
        // (rejection reopens the rejected steps, full approval reopens all for renewal)
        if (!$stepRow) {
            return new ActionResult(
                error: ErrorCode::UserPaperStepLocked,
                status: 422,
                data: ['message' => 'This step already has a submission waiting for review']
            );
        }

        // Steps without OCR need no confirmation, so uploading them can complete the submission
        if ($step->ocrGroupTypes() === []) {
            $this->stepService->markWaitForApprovalIfComplete($user->refresh());
        }

        return new ActionResult(data: $stepRow->refresh()->load('ocrResult', 'ocrResults'));
    }

    /**
     * @param array<string, mixed> $files
     * @param string[] $storedPaths filled with every file written, for cleanup on failure
     * @return ?UserPaperStep null when the step already has a submission (locked until review)
     */
    private function storeUpload(
        User $user,
        UserPaper $userPaper,
        UserPaperStepKey $step,
        array $files,
        array &$storedPaths
    ): ?UserPaperStep {
        return DB::transaction(function () use ($user, $userPaper, $step, $files, &$storedPaths) {
            // One submission per review cycle, except while OCR explicitly rejected the images
            // as unreadable and the cycle's OCR run budget is not spent yet. Read under lock so
            // an upload racing another upload/confirm cannot slip past the check.
            $stepRow = UserPaperStep::query()
                ->where('user_paper_id', $userPaper->id)
                ->where('step_key', $step->value)
                ->lockForUpdate()
                ->first();
            $needsReupload = $stepRow?->status === UserPaperStepStatus::NeedsReupload->value;
            if ($stepRow && !$needsReupload && !$stepRow->canReplaceUnreadableUpload()) {
                return null;
            }

            // Imported employees can already be approved without local document history.
            // Preserve that baseline for the whole renewal cycle, including after rejection.
            if ($user->paper_status === UserPaperStatus::Approved->value) {
                $this->stepService->markSubmissionStartedFromApproved($user);
            }

            // Un-reviewed items of these types were never seen by an admin — either leftovers
            // from the legacy one-page upload flow (no step row) or the unreadable submission
            // being replaced — so replace them for good. The files are only deleted after
            // commit so a rollback does not lose them.
            $replacedItems = $this->stepService->pendingStepItems($user, $step);
            $replacedPaths = $replacedItems->pluck('file_path')->all();
            foreach ($replacedItems as $item) {
                $item->delete();
            }
            DB::afterCommit(fn() => Storage::delete($replacedPaths));

            $storagePath = 'users/' . $user->id . '/papers';
            $values = [];
            foreach ($step->requiredTypes() as $type) {
                $typeFiles = $step->allowsMultipleFilesPerType() ? $files[$type] : [$files[$type]];
                foreach ($typeFiles as $file) {
                    $filePath = ImageHelper::handleImageUpload($file, $storagePath);
                    $storedPaths[] = $filePath;
                    $values[] = [
                        'type' => $type,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $filePath,
                    ];
                }
            }
            $userPaper->items()->createMany($values);

            if ($user->paper_status === UserPaperStatus::Registered->value) {
                $user->update(['paper_status' => UserPaperStatus::Pending->value]);
            }

            $ocrGroupTypes = $step->ocrGroupTypes();
            $skipsOcr = $ocrGroupTypes === [];

            /** @var UserPaperStep $stepRow */
            $stepRow = UserPaperStep::query()->updateOrCreate(
                ['user_paper_id' => $userPaper->id, 'step_key' => $step->value],
                [
                    'status' => $skipsOcr
                        ? UserPaperStepStatus::Confirmed->value
                        : UserPaperStepStatus::OcrProcessing->value,
                    'ocr_run_count' => ($stepRow?->ocr_run_count ?? 0) + 1,
                    'ocr_result_id' => null,
                    'confirmed_at' => $skipsOcr ? now() : null,
                    'confirmed_fields' => null,
                ]
            );

            // A replacement upload deletes the images the previous runs were computed from,
            // so those runs must stop counting as this step's OCR before the new ones start
            UserPaperOcrResult::query()
                ->where('user_paper_step_id', $stepRow->id)
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            if (!$skipsOcr) {
                $items = $this->stepService->activeStepItems($user, $step)->sortBy('id')->values();

                // One run per group so their answers stay independent and can be compared;
                // the first group's run stays the step's primary result
                $primaryResultId = null;
                foreach ($ocrGroupTypes as $group => $types) {
                    $groupItemIds = $items
                        ->filter(fn($item) => in_array($item->type, $types, true))
                        ->pluck('id')
                        ->all();
                    if ($groupItemIds === []) {
                        continue;
                    }

                    $ocrResult = $this->cacheService->createPending(
                        $group,
                        $groupItemIds,
                        OcrResultCacheService::SOURCE_EMPLOYEE,
                        $user->id
                    );
                    $ocrResult->update(['user_paper_step_id' => $stepRow->id]);
                    $primaryResultId ??= $ocrResult->id;

                    ExtractDocumentStepOcrJob::dispatch($ocrResult->id, $stepRow->id)->afterCommit();
                }

                $stepRow->update(['ocr_result_id' => $primaryResultId]);
            }

            return $stepRow;
        });
    }
}
