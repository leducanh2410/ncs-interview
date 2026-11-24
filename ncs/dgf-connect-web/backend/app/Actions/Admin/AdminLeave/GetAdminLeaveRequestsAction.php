<?php

namespace App\Actions\Admin\AdminLeave;

use App\Actions\ActionResult;
use App\Enums\AdminLeaveRequestStatus;
use App\Models\AdminLeaveRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class GetAdminLeaveRequestsAction
{
    public function __invoke(array $conditions): ActionResult
    {
        $paginator = AdminLeaveRequest::query()
            ->with(['requester', 'decidedBy'])
            ->withCount('attachments')
            ->filter($conditions)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($conditions['page_size'] ?? null);

        $overlapping = $this->resolveOverlaps($paginator);

        $paginator->through(
            fn(AdminLeaveRequest $request) => AdminLeaveRequestPayload::forList(
                $request,
                $overlapping->has($request->id)
            )
        );

        return new ActionResult(data: $paginator);
    }

    /**
     * Flag every row on the page whose leave period intersects another `pending`/`approved`
     * request from the same requester.
     *
     * Resolved with one extra query for the whole page rather than a correlated subquery per row:
     * the page's requesters and spans are collected, a single query fetches their other live
     * requests, and the intersection is decided in memory.
     *
     * @return Collection<int, true> keyed by the id of each overlapping row on the page
     */
    private function resolveOverlaps(LengthAwarePaginator $paginator): Collection
    {
        $dated = (new Collection($paginator->items()))->filter(
            fn(AdminLeaveRequest $request) => $request->start_date !== null && $request->end_date !== null
        );

        if ($dated->isEmpty()) {
            return new Collection();
        }

        $candidates = AdminLeaveRequest::query()
            ->select(['id', 'requester_id', 'start_date', 'end_date'])
            ->whereIn('requester_id', $dated->pluck('requester_id')->unique()->all())
            ->whereIn('status', [AdminLeaveRequestStatus::Pending->value, AdminLeaveRequestStatus::Approved->value])
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where(function (Builder $query) use ($dated) {
                foreach ($dated as $request) {
                    $query->orWhere(function (Builder $spanQuery) use ($request) {
                        $spanQuery
                            ->whereDate('start_date', '<=', $request->end_date->toDateString())
                            ->whereDate('end_date', '>=', $request->start_date->toDateString());
                    });
                }
            })
            ->get()
            ->groupBy('requester_id');

        $overlapping = new Collection();
        foreach ($dated as $request) {
            $conflict = ($candidates[$request->requester_id] ?? new Collection())->first(
                fn(AdminLeaveRequest $candidate) => $candidate->id !== $request->id &&
                    $candidate->start_date->toDateString() <= $request->end_date->toDateString() &&
                    $candidate->end_date->toDateString() >= $request->start_date->toDateString()
            );

            if ($conflict !== null) {
                $overlapping->put($request->id, true);
            }
        }

        return $overlapping;
    }
}
