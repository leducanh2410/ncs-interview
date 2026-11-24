<?php

namespace App\ModelFilters;

class ShiftReportFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [
        'shiftUser' => ['workplace_id'],
    ];

    public function user($userId): self
    {
        return $this->where('user_id', $userId);
    }

    public function dates($dates): self
    {
        return $this->applyDateFilter($dates, 'date');
    }
}
