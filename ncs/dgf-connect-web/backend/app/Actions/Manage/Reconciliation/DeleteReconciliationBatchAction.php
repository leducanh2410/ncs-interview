<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\ReconciliationBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteReconciliationBatchAction
{
    public function __invoke(User $actor, ReconciliationBatch $batch): ActionResult
    {
        return DB::transaction(function () use ($batch) {
            $batch = ReconciliationBatch::lockById($batch->id);
            if (!$batch->isDraft()) {
                return new ActionResult(error: ErrorCode::ReconciliationBatchNotDraft, status: 409);
            }

            $path = $batch->original_file_path;
            $batch->delete();
            if ($path) {
                DB::afterCommit(fn() => Storage::delete($path));
            }

            return new ActionResult(data: ['deleted' => true]);
        });
    }
}
