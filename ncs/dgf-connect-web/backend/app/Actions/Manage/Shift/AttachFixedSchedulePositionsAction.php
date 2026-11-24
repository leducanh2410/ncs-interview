<?php

namespace App\Actions\Manage\Shift;

use App\Models\WorkplacePosition;
use Illuminate\Database\Eloquent\Collection;

class AttachFixedSchedulePositionsAction
{
    public const SOURCE_COLUMN = 'fixed_schedule_workplace_position_id';

    public function __invoke(Collection $fixedScheduleUsers): void
    {
        $positionIds = $fixedScheduleUsers->pluck(self::SOURCE_COLUMN)->filter()->unique();
        $positions = $positionIds->isEmpty()
            ? collect()
            : WorkplacePosition::query()
                ->whereIn('id', $positionIds)
                ->get(['id', 'name'])
                ->keyBy('id');

        foreach ($fixedScheduleUsers as $fixedUser) {
            $position = $positions->get($fixedUser->{self::SOURCE_COLUMN});
            $fixedUser->setAttribute(
                'fixed_schedule_position',
                $position ? ['id' => $position->id, 'name' => $position->name] : null
            );
            $fixedUser->offsetUnset(self::SOURCE_COLUMN);
        }
    }
}
