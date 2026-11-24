<?php

namespace App\Actions\Admin\FixedSchedule;

use App\Actions\ActionResult;
use App\Exceptions\FixedScheduleBulkResolveAbortedException;
use App\Models\FixedScheduleChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BulkResolveFixedScheduleChangeRequestsAction
{
    public function __construct(
        private readonly ApproveFixedScheduleChangeRequestAction $approveAction,
        private readonly RejectFixedScheduleChangeRequestAction $rejectAction
    ) {}

    /**
     * @param int[] $ids
     */
    public function __invoke(User $user, array $ids, string $action): ActionResult
    {
        try {
            return DB::transaction(function () use ($user, $ids, $action) {
                $resolved = collect();

                foreach ($ids as $id) {
                    $request = FixedScheduleChangeRequest::query()->findOrFail($id);
                    $result =
                        $action === 'approve'
                            ? ($this->approveAction)($user, $request)
                            : ($this->rejectAction)($user, $request);

                    if ($result->getError()) {
                        throw new FixedScheduleBulkResolveAbortedException($result);
                    }

                    $resolved->push($result->getData());
                }

                return new ActionResult(data: ['resolved' => $resolved->values()]);
            });
        } catch (FixedScheduleBulkResolveAbortedException $e) {
            return $e->result;
        }
    }
}
