<?php

namespace App\Jobs;

use App\Actions\Manage\Shift\CreateShiftAdminAlertAction;
use App\Actions\Manage\Shift\ResolveWorkplaceShiftAdminsAction;
use App\Enums\NotificationType;
use App\Enums\ShiftUserStatus;
use App\Helpers\WorkHelper;
use App\Models\Shift;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emits the two aggregated administrator alerts (Requirements 2 and 3). Kept separate from
 * ShiftReminder because these alerts are per-shift rather than per-assignment and would
 * otherwise have to thread through that job's `continue` branches, which were written for a
 * different purpose.
 */
class ShiftAdminAlert implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $resolveAdmins = new ResolveWorkplaceShiftAdminsAction();
        $createAlert = new CreateShiftAdminAlertAction();

        $shifts = Shift::query()
            // Includes yesterday so an overnight shift (e.g. 23:55-08:00) is still a
            // candidate once we're past midnight; evaluate*Alert() below filters out
            // shifts whose computed $startTime/$endTime are no longer in the alert window.
            ->whereBetween('shifts.date', [now()->subDay()->startOfDay(), now()->addDay()->endOfDay()])
            ->where(function (Builder $query) {
                $query->whereNull('shifts.unconfirmed_alerted_at')->orWhereNull('shifts.missing_check_in_alerted_at');
            })
            ->whereHas('shiftUsers', fn(Builder $query) => $query->where('status', ShiftUserStatus::Approved))
            ->with(['workplace', 'workplaceShift'])
            ->get();

        if ($shifts->isEmpty()) {
            return;
        }

        $adminsByWorkplace = $resolveAdmins($shifts->pluck('workplace_id')->unique()->values()->all());

        foreach ($shifts as $shift) {
            if (!$shift->workplaceShift?->start_time || !$shift->workplaceShift?->end_time) {
                Log::warning('ShiftAdminAlert: skipping shift with no workplace shift time window', [
                    'shift_id' => $shift->id,
                ]);

                continue;
            }

            [$startTime, $endTime] = WorkHelper::getShiftTimes(
                $shift->date,
                $shift->workplaceShift->start_time,
                $shift->workplaceShift->end_time,
                $shift->workplaceShift->start_break_time,
                $shift->workplaceShift->end_break_time
            );

            $minutesUntilStart = (int) now()->diffInMinutes($startTime);
            $minutesUntilEnd = (int) now()->diffInMinutes($endTime);
            $admins = $adminsByWorkplace[$shift->workplace_id] ?? [];

            // Each alert kind gets its own transaction: a failure evaluating one must not
            // roll back the other if it already committed successfully for this shift.
            try {
                DB::transaction(
                    fn() => $this->evaluateUnconfirmedAlert(
                        $shift,
                        $createAlert,
                        $admins,
                        $startTime,
                        $minutesUntilStart
                    )
                );
            } catch (Throwable $exception) {
                $this->logFailure($shift, $exception);
            }

            try {
                DB::transaction(
                    fn() => $this->evaluateMissingCheckInAlert(
                        $shift,
                        $createAlert,
                        $admins,
                        $startTime,
                        $minutesUntilStart,
                        $minutesUntilEnd
                    )
                );
            } catch (Throwable $exception) {
                $this->logFailure($shift, $exception);
            }
        }
    }

    private function logFailure(Shift $shift, Throwable $exception): void
    {
        // Leaves this shift's alert timestamp unset so the next run retries it, without
        // rolling back alerts already committed for other shifts (or the other alert kind
        // of this same shift) in this batch.
        Log::error('ShiftAdminAlert: failed to process shift', [
            'shift_id' => $shift->id,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * @param int[] $admins
     */
    private function evaluateUnconfirmedAlert(
        Shift $shift,
        CreateShiftAdminAlertAction $createAlert,
        array $admins,
        Carbon $startTime,
        int $minutesUntilStart
    ): void {
        if ($shift->unconfirmed_alerted_at) {
            return;
        }

        $beforeMinute = config('work.reminder.unconfirmed_alert.before_minute');
        if ($minutesUntilStart < 0 || $minutesUntilStart > $beforeMinute) {
            return;
        }

        // Re-read status fresh (locked) instead of trusting the collection loaded before
        // the loop: a user may confirm between that load and this shift being processed.
        // lockForUpdate() here also blocks on/blocks a concurrent confirmSchedule() write,
        // so whichever commits first is what the alert reflects.
        $unconfirmedUserIds = $shift
            ->shiftUsers()
            ->where('status', ShiftUserStatus::Approved)
            ->whereNull('confirmed_at')
            ->lockForUpdate()
            ->pluck('user_id')
            ->values()
            ->all();
        if (empty($unconfirmedUserIds)) {
            return;
        }

        // Conditional update claims the alert atomically: if another worker already sent
        // it (row no longer NULL), this affects 0 rows and we skip sending a duplicate. A
        // failure later in this transaction rolls the claim back too, leaving it retryable.
        $claimed = Shift::query()
            ->whereKey($shift->id)
            ->whereNull('unconfirmed_alerted_at')
            ->update([
                'unconfirmed_alerted_at' => now(),
            ]);
        if ($claimed === 0) {
            return;
        }

        $createAlert($shift, NotificationType::SHIFT_UNCONFIRMED_ALERT, $admins, $unconfirmedUserIds, $startTime);

        if (empty($admins)) {
            Log::debug('ShiftAdminAlert: no unconfirmed-alert recipients for workplace', [
                'shift_id' => $shift->id,
                'workplace_id' => $shift->workplace_id,
            ]);
        }
    }

    /**
     * @param int[] $admins
     */
    private function evaluateMissingCheckInAlert(
        Shift $shift,
        CreateShiftAdminAlertAction $createAlert,
        array $admins,
        Carbon $startTime,
        int $minutesUntilStart,
        int $minutesUntilEnd
    ): void {
        if ($shift->missing_check_in_alerted_at) {
            return;
        }

        $afterMinute = config('work.reminder.missing_check_in_alert.after_minute');
        if ($minutesUntilStart > -$afterMinute || $minutesUntilEnd <= 0) {
            return;
        }

        // Re-read fresh (locked) rather than the collection loaded before the loop: a
        // user may check in between that load and this shift being processed.
        // CoreCheckInAction takes lockForUpdate() on the same shift_users row before
        // inserting the check-in, so this blocks on/blocks that write consistently.
        $missingUserIds = $shift
            ->shiftUsers()
            ->where('status', ShiftUserStatus::Approved)
            ->whereDoesntHave('checkIn', fn(Builder $query) => $query->whereNotNull('check_in_time'))
            ->lockForUpdate()
            ->pluck('user_id')
            ->values()
            ->all();
        if (empty($missingUserIds)) {
            return;
        }

        // Conditional update claims the alert atomically: if another worker already sent
        // it (row no longer NULL), this affects 0 rows and we skip sending a duplicate. A
        // failure later in this transaction rolls the claim back too, leaving it retryable.
        $claimed = Shift::query()
            ->whereKey($shift->id)
            ->whereNull('missing_check_in_alerted_at')
            ->update([
                'missing_check_in_alerted_at' => now(),
            ]);
        if ($claimed === 0) {
            return;
        }

        $createAlert($shift, NotificationType::SHIFT_MISSING_CHECK_IN_ALERT, $admins, $missingUserIds, $startTime);

        if (empty($admins)) {
            Log::debug('ShiftAdminAlert: no missing-check-in-alert recipients for workplace', [
                'shift_id' => $shift->id,
                'workplace_id' => $shift->workplace_id,
            ]);
        }
    }
}
