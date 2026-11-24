<?php

namespace App\Actions\Admin\Workplace;

use App\Actions\ActionResult;
use App\Actions\Manage\Shift\FindWeeklyHourViolationsAction;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateUpdateShiftAction
{
    public function __construct(private readonly FindWeeklyHourViolationsAction $findWeeklyHourViolations) {}

    public function __invoke(Workplace $workplace, ?WorkplaceShift $workplaceShift, array $params): ActionResult
    {
        if ($params['start_break_time'] && $params['end_break_time']) {
            $startBreakTime = Carbon::parse($params['start_break_time']);
            $endBreakTime = Carbon::parse($params['end_break_time']);
            if ($endBreakTime->lte($startBreakTime)) {
                $endBreakTime->addDay();
            }
            $params['break_duration'] = $startBreakTime->diffInMinutes($endBreakTime);
        } else {
            $params['break_duration'] = 0;
        }

        $leaders = collect($params['leaders'])->pluck('bonus', 'user_id');
        unset($params['leaders']);

        DB::beginTransaction();
        try {
            if ($workplaceShift) {
                $previousHours = $this->measureHours($workplaceShift);
                $workplaceShift->update($params);
                $violations = $this->isLengthened($previousHours, $this->measureHours($workplaceShift))
                    ? $this->findUpcomingViolations($workplaceShift)
                    : [];
                if (!empty($violations)) {
                    DB::rollBack();

                    return new ActionResult(
                        error: ErrorCode::ScheduleMaxWorkHoursExceeded,
                        status: 422,
                        data: $violations
                    );
                }
            } else {
                $workplaceShift = $workplace->shifts()->create($params);
            }

            $this->syncLeaders($workplaceShift, $leaders);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return new ActionResult(data: $workplaceShift);
    }

    private function syncLeaders(WorkplaceShift $workplaceShift, Collection $leaders): void
    {
        $leaderIds = $leaders->keys();
        if ($leaderIds->isEmpty()) {
            $workplaceShift->leaders()->delete();

            return;
        }

        $currentLeaders = $workplaceShift->leaders->keyBy('user_id');
        foreach ($leaders as $userId => $bonus) {
            $leader = $currentLeaders->get($userId);
            if ($leader) {
                $leader->update(['bonus_per_hour' => $bonus]);
            } else {
                $workplaceShift->leaders()->create([
                    'user_id' => $userId,
                    'bonus_per_hour' => $bonus,
                ]);
            }
        }

        $workplaceShift->leaders()->whereNotIn('user_id', $leaderIds->toArray())->delete();
    }

    /**
     * @return array{worked: float, span: float}
     */
    private function measureHours(WorkplaceShift $workplaceShift): array
    {
        $date = Carbon::today()->toDateString();
        [$start, $end] = WorkHelper::getShiftTimes(
            $date,
            $workplaceShift->start_time,
            $workplaceShift->end_time,
            $workplaceShift->start_break_time,
            $workplaceShift->end_break_time
        );

        return [
            'worked' => WorkHelper::computeShiftWorkData(
                $date,
                $workplaceShift->start_time,
                $workplaceShift->end_time,
                $workplaceShift->start_break_time,
                $workplaceShift->end_break_time,
                null
            )['hour'],
            'span' => $start->diffInMinutes($end) / 60,
        ];
    }

    /**
     * @param array{worked: float, span: float} $previous
     * @param array{worked: float, span: float} $current
     */
    private function isLengthened(array $previous, array $current): bool
    {
        return $current['worked'] > $previous['worked'] || $current['span'] > $previous['span'];
    }

    /**
     * @return list<array{user_id: int, staff_no: ?string, name: string, date: string, over_hours: float}>
     */
    private function findUpcomingViolations(WorkplaceShift $workplaceShift): array
    {
        $shifts = Shift::query()
            ->where('workplace_shift_id', $workplaceShift->id)
            ->where('date', '>=', Carbon::today()->toDateString())
            ->whereHas('shiftUsers', fn($query) => $query->where('status', ShiftUserStatus::Approved->value))
            ->orderBy('date')
            ->get();

        $violations = [];
        foreach ($shifts as $shift) {
            $userIds = $shift
                ->shiftUsers()
                ->where('status', ShiftUserStatus::Approved->value)
                ->where('is_absent', false)
                ->whereDoesntHave('checkIn', fn($query) => $query->whereNotNull('check_out_time'))
                ->pluck('user_id')
                ->all();
            foreach (($this->findWeeklyHourViolations)($shift, $userIds) as $userId => $overHours) {
                $violations[] = ['user_id' => $userId, 'date' => $shift->date, 'over_hours' => round($overHours, 2)];
            }
        }

        if (empty($violations)) {
            return [];
        }

        $users = User::query()->whereIn('id', array_column($violations, 'user_id'))->get()->keyBy('id');

        return array_map(
            fn(array $violation) => [
                'user_id' => $violation['user_id'],
                'staff_no' => $users->get($violation['user_id'])?->staff_no,
                'name' => (string) $users->get($violation['user_id'])?->short_name,
                'date' => Carbon::parse($violation['date'])->toDateString(),
                'over_hours' => $violation['over_hours'],
            ],
            $violations
        );
    }
}
