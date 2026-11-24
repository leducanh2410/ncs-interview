<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

class FixedScheduleFilter extends ModelFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    public function user(int $userId): FixedScheduleFilter
    {
        return $this->where('user_id', $userId);
    }

    public function workplace(int $workplaceId): FixedScheduleFilter
    {
        return $this->where('workplace_id', $workplaceId);
    }
}
