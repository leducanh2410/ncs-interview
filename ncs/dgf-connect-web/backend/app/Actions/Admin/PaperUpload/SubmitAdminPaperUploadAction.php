<?php

namespace App\Actions\Admin\PaperUpload;

use App\Actions\ActionResult;
use App\Enums\AdminPaperUploadStatus;
use App\Enums\ErrorCode;
use App\Http\Requests\Admin\AdminPaperUpload\SubmitRequest;
use App\Models\AdminPaperUpload;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubmitAdminPaperUploadAction
{
    /**
     * @param array<string, mixed> $fields the values the admin accepted or corrected
     */
    public function __invoke(AdminPaperUpload $upload, array $fields, User $admin): ActionResult
    {
        if ($upload->uploaded_by_id !== $admin->id) {
            return new ActionResult(
                error: ErrorCode::Forbidden,
                status: 403,
                data: ['message' => 'This upload belongs to another admin']
            );
        }

        if ($upload->status === AdminPaperUploadStatus::Submitted->value) {
            return $this->alreadySubmitted();
        }

        if ($upload->status === AdminPaperUploadStatus::OcrProcessing->value) {
            // A submission goes through OCR, never around it.
            return new ActionResult(
                error: ErrorCode::AdminPaperUploadOcrPending,
                status: 422,
                data: ['message' => 'The card is still being read; wait for the read to finish']
            );
        }

        $manualFallback = $upload->status === AdminPaperUploadStatus::OcrFailed->value;
        if ($manualFallback && !$upload->allowsManualConfirmation()) {
            return new ActionResult(
                error: ErrorCode::AdminPaperUploadOcrUnreadable,
                status: 422,
                data: [
                    'message' => 'The card could not be read; retake the photo',
                    'ocr_run_count' => $upload->ocr_run_count,
                    'max_ocr_runs' => AdminPaperUpload::MAX_OCR_RUNS,
                ]
            );
        }

        $confirmed = $this->confirmedFields($upload, $fields, $manualFallback);

        $updated = DB::transaction(
            fn() => AdminPaperUpload::query()
                ->whereKey($upload->id)
                ->whereNull('submitted_at')
                ->update([
                    // Builder::update() bypasses the model's json casts, so encode here.
                    'confirmed_fields' => json_encode($confirmed, JSON_UNESCAPED_UNICODE),
                    'confirmed_without_ocr' => $manualFallback,
                    'status' => AdminPaperUploadStatus::Submitted->value,
                    'submitted_by_id' => $admin->id,
                    'submitted_at' => now(),
                ])
        );

        if ($updated === 0) {
            // A concurrent submit won. Conditional update rather than check-then-act, so
            // the loser reports the same error it would have got by arriving second.
            return $this->alreadySubmitted();
        }

        $upload->refresh()->load('submittedBy:id,last_name,first_name');

        return new ActionResult(
            data: [
                'id' => $upload->id,
                'status' => $upload->status,
                'submitted_at' => $upload->submitted_at?->toIso8601String(),
                'submitted_by' => [
                    'id' => $upload->submitted_by_id,
                    'name' => $upload->submittedBy
                        ? mb_trim($upload->submittedBy->last_name . ' ' . $upload->submittedBy->first_name)
                        : null,
                ],
                'confirmed_without_ocr' => $upload->confirmed_without_ocr,
                'confirmed_fields' => $upload->confirmed_fields,
            ]
        );
    }

    /**
     * The admin's values, with residence_status_raw settled by the server.
     *
     * After a successful read it is carried over from what the card actually said and any
     * client value is ignored, so the record keeps the printed wording rather than
     * whatever a form round-trip produced. Only the manual fallback has no machine read to
     * carry over, so only there may the admin supply it.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function confirmedFields(AdminPaperUpload $upload, array $fields, bool $manualFallback): array
    {
        $confirmed = array_intersect_key($fields, array_flip(SubmitRequest::allowedFields()));

        if (!$manualFallback) {
            $confirmed['residence_status_raw'] = $upload->ocr_fields['residence_status_raw'] ?? null;
        }

        return $confirmed;
    }

    private function alreadySubmitted(): ActionResult
    {
        return new ActionResult(
            error: ErrorCode::AdminPaperUploadAlreadySubmitted,
            status: 422,
            data: ['message' => 'This upload has already been submitted']
        );
    }
}
