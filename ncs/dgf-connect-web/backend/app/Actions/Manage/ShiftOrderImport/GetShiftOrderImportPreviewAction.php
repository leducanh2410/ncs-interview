<?php

namespace App\Actions\Manage\ShiftOrderImport;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\ImportBatch;
use App\Models\User;

class GetShiftOrderImportPreviewAction
{
    public function __construct(private readonly BuildShiftOrderPreviewAction $buildPreview) {}

    public function __invoke(User $user, ImportBatch $batch): ActionResult
    {
        if (!$batch->isShiftOrder() || $batch->imported_by !== $user->id) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        if (!$user->hasPermission(PermissionName::ShiftImportOrder->value)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        if (!$batch->isPending()) {
            return new ActionResult(error: ErrorCode::ShiftOrderBatchNotPending, status: 400);
        }

        return new ActionResult(
            data: [
                'batch' => $batch,
                'month' => $batch->meta['month'] ?? null,
                'detected_month' => $batch->meta['detected_month'] ?? null,
                'preview' => ($this->buildPreview)($batch->meta['plan'] ?? []),
            ]
        );
    }
}
