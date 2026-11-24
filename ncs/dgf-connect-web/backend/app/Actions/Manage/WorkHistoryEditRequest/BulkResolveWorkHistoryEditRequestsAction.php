<?php

namespace App\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Exceptions\WorkHistoryEditRequestBulkResolveAbortedException;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BulkResolveWorkHistoryEditRequestsAction
{
    public function __construct(
        private readonly ApproveWorkHistoryEditRequestAction $approveAction,
        private readonly RejectWorkHistoryEditRequestAction $rejectAction
    ) {}

    /**
     * @param int[] $ids
     */
    public function __invoke(User $user, array $ids, string $action, ?string $rejectReason = null): ActionResult
    {
        // Lock requests (and, inside approve/reject, their check-ins) in a consistent order
        // regardless of what order the caller sent them in, so two concurrent bulk-resolve
        // calls over overlapping id sets can't lock-wait on each other in opposite directions
        // and deadlock.
        $ids = collect($ids)->unique()->sort()->values()->all();

        try {
            return DB::transaction(function () use ($user, $ids, $action, $rejectReason) {
                $resolved = collect();

                foreach ($ids as $id) {
                    $request = WorkHistoryEditRequest::query()->findOrFail($id);
                    $result =
                        $action === 'approve'
                            ? ($this->approveAction)($user, $request)
                            : ($this->rejectAction)($user, $request, $rejectReason ?? '');

                    if ($result->getError()) {
                        throw new WorkHistoryEditRequestBulkResolveAbortedException(
                            $this->withRequestIdentity($result, $request)
                        );
                    }

                    $resolved->push($result->getData());
                }

                return new ActionResult(data: ['resolved' => $resolved->values()]);
            });
        } catch (WorkHistoryEditRequestBulkResolveAbortedException $e) {
            return $e->result;
        }
    }

    private function withRequestIdentity(ActionResult $result, WorkHistoryEditRequest $request): ActionResult
    {
        if ($result->getError() === ErrorCode::Forbidden) {
            return $result;
        }

        $request->loadMissing('checkIn.user', 'checkIn.shift');

        return new ActionResult(
            error: $result->getError(),
            status: $result->getStatus(),
            data: array_merge(
                [
                    'request_id' => $request->id,
                    'employee_name' => $request->checkIn->user->name,
                    'date' => Carbon::parse($request->checkIn->shift->date)->format('Y/n/j'),
                ],
                (array) $result->getData()
            )
        );
    }
}
