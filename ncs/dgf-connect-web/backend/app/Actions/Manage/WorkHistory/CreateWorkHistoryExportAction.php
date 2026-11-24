<?php

namespace App\Actions\Manage\WorkHistory;

use App\Enums\ExportBatchStatus;
use App\Enums\ExportBatchType;
use App\Jobs\WorkHistoryExportJob;
use App\Models\ExportBatch;
use App\Models\User;
use App\Services\Manage\WorkHistoryService;
use Illuminate\Support\Facades\DB;

class CreateWorkHistoryExportAction
{
    public function __construct(private readonly WorkHistoryService $workHistoryService) {}

    public function execute(User $user, array $conditions): ?ExportBatch
    {
        $totalWorkplaces = count($this->workHistoryService->getDetailExportWorkplaceIds($conditions, $user));

        if ($totalWorkplaces === 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $conditions, $totalWorkplaces): ExportBatch {
            $batch = ExportBatch::query()->create([
                'type' => ExportBatchType::WorkHistory,
                'exported_by' => $user->id,
                'status' => ExportBatchStatus::Pending,
                'csv_total_count' => $totalWorkplaces,
                'csv_completed_count' => 0,
                'csv_failed_count' => 0,
                'meta' => [
                    'conditions' => $conditions,
                ],
            ]);

            dispatch(new WorkHistoryExportJob($batch->id))->afterCommit();

            return $batch;
        });
    }
}
