<?php

namespace App\Actions\User\FixedSchedule;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\FixedScheduleChangeRequestStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeConfig;
use App\Models\FixedScheduleChangeRequest;
use App\Models\FixedScheduleChangeRequestDay;
use App\Models\User;
use App\Models\WorkplaceShift;
use Illuminate\Support\Facades\DB;

class CreateFixedScheduleChangeRequestAction
{
    /**
     * @param array{fixed_schedule_id: int, days: array<int, array{day: int, requested_workplace_shift_id: ?int}>} $data
     */
    public function __invoke(User $user, array $data): ActionResult
    {
        $schedule = FixedSchedule::query()->with('details')->find($data['fixed_schedule_id']);

        if (!$schedule || $schedule->user_id !== $user->id) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $requestedShiftIds = collect($data['days'])
            ->pluck('requested_workplace_shift_id')
            ->filter()
            ->unique()
            ->values();
        if ($requestedShiftIds->isNotEmpty()) {
            $validShiftCount = WorkplaceShift::query()
                ->where('workplace_id', $schedule->workplace_id)
                ->where('is_active', true)
                ->whereIn('id', $requestedShiftIds)
                ->count();
            if ($validShiftCount !== $requestedShiftIds->count()) {
                return new ActionResult(error: ErrorCode::FixedScheduleInvalidShift, status: 422);
            }
        }

        $currentByDay = $schedule->details->keyBy('day');
        $changedDays = collect($data['days'])
            ->filter(
                fn(array $day) => $currentByDay->get($day['day'])?->workplace_shift_id !==
                    $day['requested_workplace_shift_id']
            )
            ->values();

        if ($changedDays->isEmpty()) {
            return new ActionResult(error: ErrorCode::FixedScheduleNoChangedDays, status: 422);
        }

        return DB::transaction(function () use ($schedule, $changedDays, $currentByDay) {
            // Lock the config row and check the window here (not before the transaction) so an
            // admin closing the window concurrently can't be raced past by an in-flight submission.
            $config = FixedScheduleChangeConfig::query()
                ->where('workplace_id', $schedule->workplace_id)
                ->lockForUpdate()
                ->first();
            if (!$config || !$config->is_window_open) {
                return new ActionResult(error: ErrorCode::FixedScheduleWindowClosed, status: 422);
            }

            // Lock the parent schedule row to serialize concurrent submissions for this schedule.
            FixedSchedule::query()->where('id', $schedule->id)->lockForUpdate()->first();

            $hasPending = FixedScheduleChangeRequest::query()
                ->where('fixed_schedule_id', $schedule->id)
                ->where('status', FixedScheduleChangeRequestStatus::Pending->value)
                ->exists();
            if ($hasPending) {
                return new ActionResult(error: ErrorCode::FixedScheduleRequestAlreadyPending, status: 422);
            }

            $request = FixedScheduleChangeRequest::query()->create([
                'fixed_schedule_id' => $schedule->id,
                'workplace_id' => $schedule->workplace_id,
                'status' => FixedScheduleChangeRequestStatus::Pending->value,
            ]);

            foreach ($changedDays as $day) {
                FixedScheduleChangeRequestDay::query()->create([
                    'fixed_schedule_change_request_id' => $request->id,
                    'day' => $day['day'],
                    'current_workplace_shift_id' => $currentByDay->get($day['day'])?->workplace_shift_id,
                    'requested_workplace_shift_id' => $day['requested_workplace_shift_id'],
                ]);
            }

            return new ActionResult(data: $request->load('days'), status: 201);
        });
    }
}
