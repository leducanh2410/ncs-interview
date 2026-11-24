<?php

namespace App\Actions\Admin\PaperUpload;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\AdminPaperUpload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DiscardAdminPaperUploadAction
{
    public function __invoke(AdminPaperUpload $upload, User $admin): ActionResult
    {
        // A Submitted_Upload is immutable and part of the audit trail, so it is never
        // deletable through this endpoint — not even by the admin who made it.
        if ($upload->uploaded_by_id !== $admin->id || !$upload->isDraft()) {
            return new ActionResult(
                error: ErrorCode::Forbidden,
                status: 403,
                data: ['message' => 'Only your own unsubmitted draft can be discarded']
            );
        }

        $path = $upload->file_path;

        DB::transaction(function () use ($upload, $path) {
            $upload->delete();
            DB::afterCommit(fn() => Storage::delete($path));
        });

        return new ActionResult(status: 204);
    }
}
