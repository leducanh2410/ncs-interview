<?php

namespace App\Jobs;

use App\Actions\Ocr\ValidateOcrRequiredFieldsAction;
use App\Enums\AdminPaperUploadStatus;
use App\Exceptions\OcrRequiredFieldsMissingException;
use App\Models\AdminPaperUpload;
use App\Services\External\OpenRouterOcrService;
use App\Services\Ocr\OcrImagePreparer;
use App\Support\Ocr\OcrFieldNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads an admin's residence card scan, mirroring ExtractDocumentStepOcrJob's shape.
 *
 * Every write is conditional on the row still being ocr_processing, so a job finishing
 * after the admin already submitted, discarded, or replaced the draft cannot move it.
 */
class ExtractAdminPaperUploadOcrJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // Two provider attempts (primary + fallback) can take up to ~2x the provider timeout
    public int $timeout = 130;

    public function __construct(private readonly int $uploadId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10];
    }

    public function handle(
        OcrImagePreparer $preparer,
        OpenRouterOcrService $ocr,
        OcrFieldNormalizer $normalizer,
        ValidateOcrRequiredFieldsAction $validateRequiredFields
    ): void {
        $row = AdminPaperUpload::query()->find($this->uploadId);
        if (!$row || $row->status !== AdminPaperUploadStatus::OcrProcessing->value) {
            // Missing, already submitted, discarded, or superseded by a retake: spend nothing.
            return;
        }

        $group = OpenRouterOcrService::GROUP_RESIDENCE_CARD_SCAN;

        // Anything thrown here is a provider or storage failure: let it out so the queue
        // retries, and let failed() record the outcome once the attempts are spent.
        $image = $preparer($row->file_path);
        $extracted = $ocr->extract($group, [$image], retryWithFallback: true);
        $normalized = $normalizer->normalize($group, $extracted['fields']);

        try {
            $validateRequiredFields($group, $normalized['fields']);
        } catch (OcrRequiredFieldsMissingException) {
            // The same image will not read better on a retry, and the client needs the
            // failure now: keep the partial read, record it, and spend no queue attempt.
            $this->finish([
                'status' => AdminPaperUploadStatus::OcrFailed->value,
                'ocr_fields' => $normalized['fields'],
                'ocr_warnings' => $normalized['warnings'],
                'ocr_model' => $extracted['model'],
                'ocr_error' => OcrRequiredFieldsMissingException::ERROR_CODE,
                'ocr_completed_at' => now(),
            ]);

            return;
        }

        $this->finish([
            'status' => AdminPaperUploadStatus::OcrReady->value,
            'ocr_fields' => $normalized['fields'],
            'ocr_warnings' => $normalized['warnings'],
            'ocr_model' => $extracted['model'],
            'ocr_error' => null,
            'ocr_completed_at' => now(),
        ]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Admin paper upload OCR job failed', [
            'admin_paper_upload_id' => $this->uploadId,
            'error' => $e?->getMessage(),
        ]);

        $this->finish([
            'status' => AdminPaperUploadStatus::OcrFailed->value,
            'ocr_error' => mb_substr($e?->getMessage() ?? 'unknown', 0, 100),
            'ocr_completed_at' => now(),
        ]);
    }

    /**
     * Conditional update rather than check-then-act: the row must still be the one this
     * job was dispatched for, in the status it was dispatched in.
     *
     * Array values are encoded here because Eloquent's Builder::update() writes straight
     * through to the query builder and never applies the model's json casts.
     *
     * @param array<string, mixed> $attributes
     */
    private function finish(array $attributes): void
    {
        $attributes = array_map(
            fn($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value,
            $attributes
        );

        AdminPaperUpload::query()
            ->whereKey($this->uploadId)
            ->where('status', AdminPaperUploadStatus::OcrProcessing->value)
            ->update($attributes);
    }
}
