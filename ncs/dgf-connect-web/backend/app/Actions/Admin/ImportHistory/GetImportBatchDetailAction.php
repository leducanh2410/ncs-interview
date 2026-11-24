<?php

namespace App\Actions\Admin\ImportHistory;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\ImportBatch;
use App\Models\User;

class GetImportBatchDetailAction
{
    public function __invoke(User $user, ImportBatch $batch): ActionResult
    {
        if ($batch->isPending() || !$this->isVisibleTo($user, $batch)) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $batch->load('importer:id,first_name,last_name,short_name,staff_no,profile_picture');

        $meta = $batch->meta ?? [];
        $plan = $meta['plan'] ?? [];
        $decisions = $meta['decisions'] ?? [];

        $workplaces = [];
        foreach ($plan as $item) {
            $key = $item['workplace_id'];
            $workplaces[$key] ??= [
                'workplace_id' => $item['workplace_id'],
                'workplace_name' => $item['workplace_name'],
                'applied_count' => 0,
                'skipped_count' => 0,
            ];

            $source = $decisions[$item['workplace_shift_id'] . '|' . $item['date']] ?? 'file';
            if ($source === 'system') {
                $workplaces[$key]['skipped_count']++;

                continue;
            }
            $workplaces[$key]['applied_count']++;
        }

        $workplaces = array_values($workplaces);

        return new ActionResult(
            data: [
                'batch' => $batch,
                'month' => $meta['month'] ?? null,
                'sheet_name' => $meta['sheet_name'] ?? null,
                'skipped_count' => $meta['skipped_count'] ?? 0,
                'workplaces' => $workplaces,
            ]
        );
    }

    private function isVisibleTo(User $user, ImportBatch $batch): bool
    {
        return $batch->imported_by === $user->id ||
            $user->hasUnrestrictedWorkplaceAccess(PermissionName::ShiftImportOrder->value);
    }
}
