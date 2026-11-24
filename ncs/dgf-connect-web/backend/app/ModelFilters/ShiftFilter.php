<?php

namespace App\ModelFilters;

class ShiftFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    public function date(string $date): ShiftFilter
    {
        return $this->whereDate('date', '=', $date);
    }

    public function dates($dates): ShiftFilter
    {
        return $this->applyDateFilter($dates, 'date');
    }

    public function workplace(int $workplaceId): ShiftFilter
    {
        if ($workplaceId === self::ALL_VALUE_INT) {
            return $this;
        }

        return $this->where('workplace_id', $workplaceId);
    }
}
