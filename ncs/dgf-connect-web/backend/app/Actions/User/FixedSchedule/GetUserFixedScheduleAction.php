<?php

namespace App\Actions\User\FixedSchedule;

use App\Actions\ActionResult;
use App\Enums\FixedScheduleChangeRequestStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeConfig;
use App\Models\FixedScheduleChangeRequest;
use App\Models\User;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;

class GetUserFixedScheduleAction
{
    /**
     * Always returns a list (0, 1, or many items) — an employee may have a Fixed_Schedule
     * at more than one workplace, and it's the mobile client's job to show a grid directly
     * or a workplace picker first (Requirement 9.4), not this endpoint's.
     */
    public function __invoke(User $user, ?int $workplaceId): ActionResult
    {
        $query = FixedSchedule::query()
            ->where('user_id', $user->id)
            ->with(['workplace', 'details.workplaceShift', 'details.workplacePosition']);

        if ($workplaceId !== null) {
            $query->where('workplace_id', $workplaceId);
        }

        $schedules = $query->get();

        $data = $schedules
            ->map(function (FixedSchedule $schedule) {
                $config = FixedScheduleChangeConfig::query()->where('workplace_id', $schedule->workplace_id)->first();

                $relevantRequest = FixedScheduleChangeRequest::query()
                    ->where('fixed_schedule_id', $schedule->id)
                    ->with('days.requestedWorkplaceShift')
                    ->orderByDesc('created_at')
                    ->first();

                return $this->toResource($schedule, $config, $relevantRequest);
            })
            ->values();

        return new ActionResult(data: $data);
    }

    private function toResource(
        FixedSchedule $schedule,
        ?FixedScheduleChangeConfig $config,
        ?FixedScheduleChangeRequest $relevantRequest
    ): array {
        $detailsByDay = $schedule->details->keyBy('day');
        $changeByDay = $relevantRequest ? $relevantRequest->days->keyBy('day') : collect();

        return [
            'fixed_schedule_id' => $schedule->id,
            'workplace' => ['id' => $schedule->workplace->id, 'name' => $schedule->workplace->name],
            'change_window' => [
                'is_open' => $config?->is_window_open ?? false,
                'deadline' => $config?->deadline?->format('Y-m-d'),
            ],
            'pending_request' =>
                $relevantRequest && $relevantRequest->status === FixedScheduleChangeRequestStatus::Pending->value
                    ? ['id' => $relevantRequest->id, 'status' => $relevantRequest->status]
                    : null,
            // The workplace's shift catalog an employee may request in edit mode — without
            // it, the mobile edit flow can only ever offer "Xin nghỉ" (day off), never a
            // different shift, since it has nothing else to list (Technical Constraints:
            // no free-form time entry, only shifts already in the workplace's catalog).
            'shift_catalog' => WorkplaceShift::query()
                ->where('workplace_id', $schedule->workplace_id)
                ->where('is_active', true)
                ->orderBy('start_time')
                ->orderBy('end_time')
                ->get()
                ->map(fn(WorkplaceShift $shift) => $this->shiftResource($shift))
                ->values(),
            'days' => collect(range(0, 6))
                ->map(function (int $day) use ($detailsByDay, $changeByDay, $relevantRequest) {
                    $detail = $detailsByDay->get($day);
                    $change = $changeByDay->get($day);

                    return [
                        'day' => $day,
                        'workplace_shift' => $detail?->workplaceShift
                            ? $this->shiftResource($detail->workplaceShift)
                            : null,
                        'workplace_position' => $detail?->workplacePosition
                            ? $this->positionResource($detail->workplacePosition)
                            : null,
                        'pending_or_recent_change' => $change
                            ? [
                                'requested_workplace_shift' => $change->requestedWorkplaceShift
                                    ? $this->shiftResource($change->requestedWorkplaceShift)
                                    : null,
                                'status' => $relevantRequest->status,
                            ]
                            : null,
                    ];
                })
                ->values(),
        ];
    }

    private function shiftResource(WorkplaceShift $shift): array
    {
        return ['id' => $shift->id, 'start_time' => $shift->start_time, 'end_time' => $shift->end_time];
    }

    private function positionResource(WorkplacePosition $position): array
    {
        return ['id' => $position->id, 'name' => $position->name];
    }
}
