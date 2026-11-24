<?php

namespace App\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Exceptions\WorkHistoryEditRequestBulkResolveAbortedException;
use App\Models\CheckIn;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use App\Models\WorkHistoryEditRequestItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies an admin-reviewed bulk-import batch atomically: for every row, creates a
 * Work_History_Edit_Request just-in-time and immediately resolves it via the existing
 * Approve/RejectWorkHistoryEditRequestAction - so drift-detection, permission checks, and the
 * check_in update logic are all reused as-is rather than reimplemented. See design.md, Component
 * 4.
 */
class CommitBulkImportWorkHistoryEditRequestsAction
{
    private const IMPORT_REASON = 'Nhập từ file Excel (yêu cầu sửa công đồng loạt)';

    public function __construct(
        private readonly ApproveWorkHistoryEditRequestAction $approveAction,
        private readonly RejectWorkHistoryEditRequestAction $rejectAction
    ) {}

    /**
     * @param array<int, array{
     *     check_in_id: int,
     *     items: array<int, array{field: string, old_value: ?string, new_value: ?string}>,
     *     decision: 'apply'|'reject',
     *     reject_reason?: string
     * }> $rows
     */
    public function __invoke(User $user, array $rows): ActionResult
    {
        // Deterministic lock order regardless of client-submitted order, so two concurrent
        // commits over overlapping check-in sets can't lock-wait on each other in opposite
        // directions and deadlock - same rationale as BulkResolveWorkHistoryEditRequestsAction.
        usort($rows, fn(array $a, array $b) => $a['check_in_id'] <=> $b['check_in_id']);

        try {
            return DB::transaction(function () use ($user, $rows) {
                $appliedCount = 0;
                $rejectedCount = 0;

                foreach ($rows as $row) {
                    $request = $this->createPendingRequest($user, $row);

                    $result =
                        $row['decision'] === 'apply'
                            ? ($this->approveAction)($user, $request)
                            : ($this->rejectAction)($user, $request, $row['reject_reason'] ?? '');

                    if ($result->getError()) {
                        $request->loadMissing('checkIn.user', 'checkIn.shift');
                        throw new WorkHistoryEditRequestBulkResolveAbortedException(
                            $this->withRowIdentity($result, $row['check_in_id'], $request->checkIn)
                        );
                    }

                    if ($row['decision'] === 'apply') {
                        $appliedCount++;
                    } else {
                        $rejectedCount++;
                    }
                }

                return new ActionResult(data: ['applied_count' => $appliedCount, 'rejected_count' => $rejectedCount]);
            });
        } catch (WorkHistoryEditRequestBulkResolveAbortedException $e) {
            return $e->result;
        }
    }

    /**
     * Row-locks the Check_In, then - still under that lock - re-checks it has no pending request
     * before creating one. This lock-then-check order (not check-then-lock) is what prevents two
     * concurrent commits, or a commit racing a single-record submission, from both inserting a
     * pending request for the same Check_In: there is no unique DB constraint on
     * (check_in_id, status = pending) to catch that after the fact, only an index. Mirrors
     * CreateWorkHistoryEditRequestAction's existing guard for the single-record flow.
     */
    private function createPendingRequest(User $user, array $row): WorkHistoryEditRequest
    {
        $checkIn = CheckIn::query()->where('id', $row['check_in_id'])->lockForUpdate()->first();
        if (!$checkIn) {
            throw new WorkHistoryEditRequestBulkResolveAbortedException(
                $this->withRowIdentity(new ActionResult(error: ErrorCode::NotFound, status: 404), $row['check_in_id'])
            );
        }

        $checkIn->loadMissing('shift', 'user');

        $hasPending = WorkHistoryEditRequest::query()
            ->where('check_in_id', $checkIn->id)
            ->where('status', WorkHistoryEditRequestStatus::Pending->value)
            ->exists();
        if ($hasPending) {
            throw new WorkHistoryEditRequestBulkResolveAbortedException(
                $this->withRowIdentity(
                    new ActionResult(error: ErrorCode::WorkHistoryEditRequestAlreadyPending, status: 409),
                    $checkIn->id,
                    $checkIn
                )
            );
        }

        $request = WorkHistoryEditRequest::query()->create([
            'check_in_id' => $checkIn->id,
            'workplace_id' => $checkIn->shift->workplace_id,
            'requested_by_id' => $user->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
            'snapshot_check_in_time' => $checkIn->check_in_time,
            'snapshot_check_out_time' => $checkIn->check_out_time,
            'snapshot_break_minutes' => $checkIn->break_minutes,
        ]);

        foreach ($row['items'] as $item) {
            WorkHistoryEditRequestItem::query()->create([
                'work_history_edit_request_id' => $request->id,
                'field' => $item['field'],
                'old_value' => $item['old_value'],
                'new_value' => $item['new_value'],
                // The review screen doesn't collect a per-field reason for a row left as "apply"
                // (only a rejected row's reason is admin-entered, which becomes reject_reason) -
                // see design.md's Key Design Decisions.
                'reason' => self::IMPORT_REASON,
            ]);
        }

        return $request;
    }

    /**
     * Embeds which row aborted the batch - check_in_id plus the same human-readable identity
     * (employee name + shift date) the preview step already reports row errors with - so the
     * frontend can tell the admin which of the (possibly 100+) rows drifted, instead of a single
     * unexplained toast that leaves them re-importing the same file forever. $checkIn is omitted
     * only when it could not be resolved at all (the row's check_in_id no longer exists).
     */
    private function withRowIdentity(ActionResult $result, int $checkInId, ?CheckIn $checkIn = null): ActionResult
    {
        $identity = ['check_in_id' => $checkInId];
        if ($checkIn) {
            $identity['employee_name'] = $checkIn->user->name;
            $identity['date'] = Carbon::parse($checkIn->shift->date)->format('Y/n/j');
        }

        return new ActionResult(
            error: $result->getError(),
            status: $result->getStatus(),
            data: array_merge($identity, (array) $result->getData())
        );
    }
}
