<?php

namespace App\Actions\Admin\ExportHistory;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Helpers\UrlHelper;
use App\Models\ExportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class GetWorkHistoryDownloadUrlAction
{
    public function __invoke(ExportBatch $batch, User $user): ActionResult
    {
        if (
            !$batch->isWorkHistory() ||
            !$user->hasPermission(PermissionName::WorkHistoryExport->value) ||
            $batch->exported_by !== $user->id
        ) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        if (!$batch->isCompleted() || !$batch->file_path || !Storage::exists($batch->file_path)) {
            return new ActionResult(
                error: ErrorCode::NotFound,
                status: 404,
                data: ['message' => 'Export file not available.']
            );
        }

        return new ActionResult(
            data: [
                'download_url' => UrlHelper::getStorageUrl($batch->file_path),
            ]
        );
    }
}
