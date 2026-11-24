<?php

namespace App\Services\Admin;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\FixedSchedule;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FixedScheduleService
{
    public function listFixedSchedules(User $user, array $conditions): LengthAwarePaginator
    {
        $paginator = FixedSchedule::query()
            ->applyWorkplaceFilter($user, PermissionName::FixedScheduleList->value)
            ->filter($conditions)
            ->with(['user', 'workplace', 'details.workplaceShift', 'details.workplacePosition'])
            ->orderBy('id', 'asc')
            ->paginateFilter($conditions['page_size'] ?? null);

        // setRelation to a Collection, not the plain toArray() output: a plain array
        // isn't Arrayable, so Model::relationsToArray() would silently drop it from
        // the JSON response.
        return $paginator->through(function (FixedSchedule $schedule) {
            $schedule->setRelation('user', $schedule->user ? new Collection($schedule->user->toArray()) : null);

            return $schedule;
        });
    }

    public function store(User $user, array $data): array
    {
        if (!$user->isAbleTo(PermissionName::FixedScheduleCreate->value, $data['workplace_id'])) {
            return ['error' => ErrorCode::Forbidden, 'status' => 403];
        }

        $fixedSchedule = DB::transaction(function () use ($data) {
            $fixedSchedule = FixedSchedule::query()->create([
                'user_id' => $data['user_id'],
                'workplace_id' => $data['workplace_id'],
            ]);

            $records = array_map(function ($schedule) use ($fixedSchedule) {
                return [
                    'fixed_schedule_id' => $fixedSchedule->id,
                    'day' => $schedule['day'],
                    'workplace_shift_id' => $schedule['workplace_shift_id'],
                    'workplace_position_id' => $schedule['workplace_position_id'] ?? null,
                ];
            }, $data['schedules']);

            $fixedSchedule->details()->createMany($records);

            return $fixedSchedule;
        });

        return ['error' => null, 'data' => $fixedSchedule];
    }

    public function update(User $user, FixedSchedule $schedule, array $data): array
    {
        if (!$user->isAbleTo(PermissionName::FixedScheduleEdit->value, $schedule->workplace_id)) {
            return ['error' => ErrorCode::Forbidden, 'status' => 403];
        }

        $details = $schedule->details()->get()->keyBy('day');

        $schedule = DB::transaction(function () use ($schedule, $details, $data) {
            foreach ($data['schedules'] as $newSchedule) {
                if ($details->has($newSchedule['day'])) {
                    $details->forget($newSchedule['day']);
                }
                $schedule->applyDetailForDay($newSchedule['day'], $newSchedule['workplace_shift_id'], [
                    'workplace_position_id' => $newSchedule['workplace_position_id'] ?? null,
                ]);
            }

            $deleteIds = $details->pluck('id')->toArray();
            $schedule->details()->whereIn('id', $deleteIds)->delete();

            return $schedule;
        });

        return ['error' => null, 'data' => $schedule];
    }

    public function destroy(User $user, FixedSchedule $schedule): array
    {
        if (!$user->isAbleTo(PermissionName::FixedScheduleDelete->value, $schedule->workplace_id)) {
            return ['error' => ErrorCode::Forbidden, 'status' => 403];
        }

        return ['error' => null, 'data' => $schedule->delete()];
    }
}
