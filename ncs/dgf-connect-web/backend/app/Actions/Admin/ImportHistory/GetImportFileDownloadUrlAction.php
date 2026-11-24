<?php

namespace App\Actions\Admin\ImportHistory;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Helpers\UrlHelper;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class GetImportFileDownloadUrlAction
{
    public function __invoke(User $user, ImportBatch $batch): ActionResult
    {
        $isVisible =
            $batch->imported_by === $user->id ||
            $user->hasUnrestrictedWorkplaceAccess(PermissionName::ShiftImportOrder->value);

        if ($batch->isPending() || !$isVisible || !Storage::exists($batch->file_path)) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        return new ActionResult(data: ['download_url' => UrlHelper::getStorageUrl($batch->file_path)]);
    }
}
