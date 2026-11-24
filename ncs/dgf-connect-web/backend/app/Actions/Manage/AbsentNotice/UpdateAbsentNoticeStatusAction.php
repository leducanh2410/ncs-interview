<?php

namespace App\Actions\Manage\AbsentNotice;

use App\Actions\ActionResult;
use App\Enums\AbsentNoticeShiftOutcome;
use App\Enums\AbsentNoticeStatus;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Events\AbsentNoticeRejected;
use App\Events\ShiftUserRejected;
use App\Models\CheckIn;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UpdateAbsentNoticeStatusAction
{
    public function __invoke(ShiftAbsentNotice $absentNotice, AbsentNoticeStatus $status): ActionResult
    {
        $absentNotice->loadMissing(['shiftUser.shift.workplaceShift']);
        $shiftUser = $absentNotice->shiftUser;
        $shiftDate = Carbon::parse($shiftUser->shift->date);

        $result = DB::transaction(function () use ($absentNotice, $shiftUser, $status) {
            // Locked and re-read inside the transaction so the status checks below and the writes
            // that follow cannot be split by a concurrent save from the shift screen.
            $locked = ShiftUser::query()->whereKey($shiftUser->id)->lockForUpdate()->first();

            if ($status === AbsentNoticeStatus::Rejected && $locked?->status !== ShiftUserStatus::Approved->value) {
                // Refusing the request means "come to work as planned", and the notification says
                // exactly that. It cannot be said to someone who is no longer on the shift, so the
                // transition is blocked instead of sending an instruction they cannot follow.
                return ['error' => ErrorCode::AbsentNoticeShiftNotApproved];
            }

            $isHandled = $status->isHandled();

            $absentNotice->update([
                'status' => $status,
                'processed_by_id' => $isHandled ? Auth::id() : null,
                'processed_at' => $isHandled ? now() : null,
            ]);

            if ($status !== AbsentNoticeStatus::Processed) {
                return ['outcome' => AbsentNoticeShiftOutcome::ShiftUnchanged];
            }

            return $this->rejectShiftUser($shiftUser, $locked);
        });

        if (isset($result['error'])) {
            return new ActionResult(error: $result['error'], status: 409);
        }

        $rejectedShiftUser = $result['shiftUser'] ?? null;
        if ($rejectedShiftUser) {
            ShiftUserRejected::dispatch($shiftDate, $rejectedShiftUser);
        }

        if ($status === AbsentNoticeStatus::Rejected) {
            AbsentNoticeRejected::dispatch($shiftDate, $absentNotice);
        }

        return new ActionResult(
            data: $absentNotice->refresh(),
            meta: [
                'shift_outcome' => $result['outcome']->value,
                'notified' => $this->wasEmployeeNotified($status, $rejectedShiftUser),
            ]
        );
    }

    /**
     * Accepting an absence request means the employee is no longer on the shift, so the shift row
     * follows the notice into the rejected list — the same end state the shift screen produces.
     *
     * Two situations are deliberately left alone, because rejecting the row there would destroy
     * attendance the employee actually earned:
     *
     * - The shift has already started. The employee may be working right now, and rejecting the
     *   row rewrites settled work time.
     * - A check-in already exists. Check-in opens before the shift starts (see
     *   `CheckInService::isCheckInTimeValid()`), so an employee who filed a request but turned up
     *   anyway can be on the clock while the admin is still closing the request.
     *
     * In both cases `is_absent` on the shift screen is the tool for recording a no-show, and
     * marking the notice processed is just bookkeeping.
     *
     * @param ShiftUser $shiftUser the notice's row, used for the shift relation
     * @param ShiftUser|null $locked the same row re-read under a lock inside the transaction
     * @return array{outcome: AbsentNoticeShiftOutcome, shiftUser?: ShiftUser} what happened
     */
    private function rejectShiftUser(ShiftUser $shiftUser, ?ShiftUser $locked): array
    {
        if (!$locked || $locked->status === ShiftUserStatus::Rejected->value) {
            return ['outcome' => AbsentNoticeShiftOutcome::ShiftUnchanged];
        }

        [$shiftStart] = $shiftUser->shift->getTimeRange();
        if ($shiftStart->isPast()) {
            return ['outcome' => AbsentNoticeShiftOutcome::ShiftKeptAlreadyStarted];
        }

        $hasCheckIn = CheckIn::query()
            ->where('shift_id', $locked->shift_id)
            ->where('user_id', $locked->user_id)
            ->exists();
        if ($hasCheckIn) {
            return ['outcome' => AbsentNoticeShiftOutcome::ShiftKeptCheckedIn];
        }

        // Saved through the model, not a mass update, so ShiftUserObserver still reconciles the
        // shift chat room and the draft cleanup observer still runs.
        $locked->update([
            'status' => ShiftUserStatus::Rejected->value,
            'confirmed_at' => null,
            'approver_id' => Auth::id(),
            'approved_at' => now(),
        ]);

        return ['outcome' => AbsentNoticeShiftOutcome::ShiftRejected, 'shiftUser' => $locked];
    }

    /**
     * Refusing a request always notifies. Processing one only does when the shift row was actually
     * rejected and the employee had signed up themselves — `CreateNotificationForUser` stays quiet
     * for rows a manager created, so the admin must not be told a message went out.
     */
    private function wasEmployeeNotified(AbsentNoticeStatus $status, ?ShiftUser $rejectedShiftUser): bool
    {
        if ($status === AbsentNoticeStatus::Rejected) {
            return true;
        }

        return $rejectedShiftUser !== null && !$rejectedShiftUser->created_by;
    }
}
