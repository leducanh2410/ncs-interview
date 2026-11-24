<?php

namespace App\Actions\Admin\PaperUpload;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\AdminPaperUpload;
use App\Models\User;

/**
 * The poll endpoint: the client calls this until the status leaves ocr_processing.
 */
class GetAdminPaperUploadAction
{
    public function __invoke(AdminPaperUpload $upload, User $admin): ActionResult
    {
        // Owner-only, draft or submitted alike: the capture endpoints are the acting
        // admin's own workspace. Everyone else reads a submitted scan through the
        // reconciliation detail endpoint, the surface built for it.
        if ($upload->uploaded_by_id !== $admin->id) {
            return new ActionResult(
                error: ErrorCode::Forbidden,
                status: 403,
                data: ['message' => 'This upload belongs to another admin']
            );
        }

        return new ActionResult(
            data: [
                'id' => $upload->id,
                'user_id' => $upload->user_id,
                'paper_type' => $upload->paper_type,
                'status' => $upload->status,
                'ocr_fields' => $upload->ocr_fields,
                'ocr_warnings' => $upload->ocr_warnings ?? [],
                'ocr_error' => $upload->ocr_error,
                'ocr_run_count' => $upload->ocr_run_count,
                'max_ocr_runs' => AdminPaperUpload::MAX_OCR_RUNS,
                'can_retake' => $upload->canRetakeForUnreadableScan(),
                'allows_manual_confirmation' => $upload->allowsManualConfirmation(),
                'confirmed_without_ocr' => $upload->confirmed_without_ocr,
                'file_path' => $upload->file_path,
            ]
        );
    }
}
