<?php

namespace App\Jobs;

use App\Actions\User\Document\RefreshDocumentMismatchesAction;
use App\Enums\OcrResultStatus;
use App\Enums\UserPaperStepStatus;
use App\Exceptions\OcrRequiredFieldsMissingException;
use App\Exceptions\OcrResultInvalidatedException;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Models\UserPaperStep;
use App\Services\Ocr\OcrResultCacheService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractDocumentStepOcrJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // Two provider attempts (primary + fallback) can take up to ~2x the provider timeout
    public int $timeout = 130;

    public function __construct(private readonly int $ocrResultId, private readonly int $stepId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10];
    }

    public function handle(OcrResultCacheService $cacheService): void
    {
        $result = UserPaperOcrResult::query()->find($this->ocrResultId);
        if (!$result || $result->status === OcrResultStatus::Completed->value || $result->invalidated_at !== null) {
            return;
        }

        // A superseded job must not spend an LLM call: an admin rejection reopens the step for a
        // new upload, and a re-upload invalidates the previous runs (caught above). A confirm that
        // landed while this was queued is not superseding — the run still completes, it just must
        // not move the step, which syncStepStatus() takes care of.
        $step = UserPaperStep::query()->whereKey($this->stepId)->first();
        if (
            !$step ||
            $step->status === UserPaperStepStatus::NeedsReupload->value ||
            $result->user_paper_step_id !== $this->stepId
        ) {
            return;
        }

        $items = UserPaperItem::query()->whereIn('id', $result->item_ids)->orderBy('id')->get();
        if ($items->count() !== count($result->item_ids)) {
            // Some source images were hard-deleted; the result can no longer represent item_ids
            $result->update([
                'status' => OcrResultStatus::Failed->value,
                'error' => 'Source images were removed before OCR ran',
                'completed_at' => now(),
            ]);
            $this->syncStepStatus();

            return;
        }

        // Keep the step in ocr_processing between attempts: if run() throws, a later retry can still
        // succeed and transition it to ocr_ready. Only failed() (after all $tries are exhausted) marks
        // the step ocr_failed, so a successful retry is never stuck behind an intermediate failure.
        try {
            $cacheService->run($result, $items, retryWithFallback: true, enforceRequiredFields: true);
        } catch (OcrResultInvalidatedException) {
            // Rotation maintenance replaced this run and will queue OCR against the new bytes.
            return;
        } catch (OcrRequiredFieldsMissingException) {
            // A clearer image is required. This is deterministic for this upload, so do not
            // spend queue retries on the same images; expose the failure for polling immediately.
            $this->syncStepStatus();

            return;
        }

        $this->syncStepStatus();
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Document step OCR job failed', [
            'ocr_result_id' => $this->ocrResultId,
            'step_id' => $this->stepId,
            'error' => $e?->getMessage(),
        ]);

        UserPaperOcrResult::query()
            ->where('id', $this->ocrResultId)
            ->where('status', OcrResultStatus::Processing->value)
            ->update([
                'status' => OcrResultStatus::Failed->value,
                'error' => mb_substr($e?->getMessage() ?? 'unknown', 0, 100),
                'completed_at' => now(),
            ]);

        $this->syncStepStatus();
    }

    /**
     * Record the outcome of this run: refresh the verdict, then move the step on once
     * every run of the upload has an outcome — a step reading several groups is ready
     * when they all completed, and failed as soon as one failed.
     */
    private function syncStepStatus(): void
    {
        /** @var ?UserPaperStep $step */
        $step = UserPaperStep::query()->whereKey($this->stepId)->with('ocrResults', 'userPaper.user')->first();
        if (!$step) {
            return;
        }

        $this->refreshMismatches($step);
        $this->moveStepOn($step);
    }

    /**
     * The verdict is computed from completed runs, so every run that reaches an outcome
     * has to feed into it — including one that lands after the step already left
     * ocr_processing (confirmed while this was queued, or failed on another group).
     * Tying this to the status transition below left such a run out of the comparison
     * for good, and a document naming someone else stayed invisible.
     */
    private function refreshMismatches(UserPaperStep $step): void
    {
        $user = $step->userPaper?->user;
        if ($user) {
            app(RefreshDocumentMismatchesAction::class)($user);
        }
    }

    /**
     * Only a step still waiting on OCR is transitioned — a rejection + re-upload may have
     * replaced this run, and a manual confirm during processing must never be reverted.
     */
    private function moveStepOn(UserPaperStep $step): void
    {
        if ($step->status !== UserPaperStepStatus::OcrProcessing->value) {
            return;
        }

        $results = $step->liveOcrResults();
        if ($results->isEmpty()) {
            return;
        }

        if ($results->contains(fn(UserPaperOcrResult $r) => $r->status === OcrResultStatus::Failed->value)) {
            $status = UserPaperStepStatus::OcrFailed;
        } elseif ($results->every(fn(UserPaperOcrResult $r) => $r->status === OcrResultStatus::Completed->value)) {
            $status = UserPaperStepStatus::OcrReady;
        } else {
            return;
        }

        UserPaperStep::query()
            ->whereKey($step->id)
            ->where('status', UserPaperStepStatus::OcrProcessing->value)
            ->update(['status' => $status->value]);
    }
}
