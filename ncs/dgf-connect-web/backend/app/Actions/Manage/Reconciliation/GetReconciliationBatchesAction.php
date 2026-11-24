<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\ActionResult;
use App\Models\ReconciliationBatch;
use App\Models\User;
use Illuminate\Support\Carbon;

class GetReconciliationBatchesAction
{
    public function __construct(private readonly SerializeReconciliationBatchAction $serialize) {}

    public function __invoke(User $actor, array $filters): ActionResult
    {
        $query = ReconciliationBatch::query()
            ->with(['uploadedBy', 'confirmedBy', 'exportedBy'])
            ->orderByDesc('id');

        if (!empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (!empty($filters['uploaded_by'])) {
            $query->where('uploaded_by', $filters['uploaded_by']);
        }
        if (!empty($filters['dates'][0]) && !empty($filters['dates'][1])) {
            $query->whereBetween('created_at', [
                Carbon::parse($filters['dates'][0])->startOfDay(),
                Carbon::parse($filters['dates'][1])->endOfDay(),
            ]);
        }

        $paginator = $query->paginate($filters['page_size'] ?? null);
        $paginator->through(fn(ReconciliationBatch $batch) => ($this->serialize)($batch, $actor, false));

        return new ActionResult(data: $paginator);
    }
}
