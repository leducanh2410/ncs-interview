<?php

namespace App\Actions\Admin\ImportHistory;

use App\Actions\ActionResult;
use App\Enums\ImportBatchStatus;
use App\Enums\PermissionName;
use App\Models\ImportBatch;
use App\Models\User;

class GetImportHistoryAction
{
    public function __invoke(User $user, ?string $type, int $pageSize = 15): ActionResult
    {
        $query = ImportBatch::query()
            ->with('importer:id,first_name,last_name,short_name,staff_no,profile_picture')
            ->where('status', '!=', ImportBatchStatus::Pending->value)
            ->orderByDesc('created_at');

        if (!$user->hasUnrestrictedWorkplaceAccess(PermissionName::ShiftImportOrder->value)) {
            $query->where('imported_by', $user->id);
        }

        if ($type) {
            $query->where('type', $type);
        }

        return new ActionResult(data: $query->paginate($pageSize));
    }
}
