<?php

namespace App\Actions\Manage\ShiftOrderImport;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateShiftOrderImportMonthAction
{
    use ReadsShiftOrderWorkbook;

    public function __construct(
        private readonly PrepareShiftOrderPlanAction $preparePlan,
        private readonly BuildShiftOrderPreviewAction $buildPreview
    ) {}

    public function __invoke(User $user, ImportBatch $batch, string $month): ActionResult
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

        try {
            $workbook = $this->readWorkbook($batch->file_path, config('filesystems.default'));
        } catch (Throwable $exception) {
            Log::error($exception);

            return new ActionResult(error: ErrorCode::ShiftOrderFileInvalidFormat, status: 422);
        }

        $prepared = ($this->preparePlan)($user, $workbook, $month);
        if ($prepared->getError() !== null) {
            return $prepared;
        }

        $data = $prepared->getData();
        $batch->update([
            'meta' => [...$batch->meta, 'month' => $data['month'], 'plan' => $data['plan']],
        ]);

        return new ActionResult(
            data: [
                'batch' => $batch->refresh(),
                'month' => $data['month'],
                'detected_month' => $batch->meta['detected_month'] ?? null,
                'preview' => ($this->buildPreview)($data['plan']),
            ]
        );
    }
}
