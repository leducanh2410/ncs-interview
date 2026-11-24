<?php

namespace App\ModelFilters;

class ShiftUserFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [
        'shift' => ['workplace_id', 'date', 'dates'],
        'user' => ['staff_no', 'employee_name' => 'name'],
    ];

    public function user($userId): ShiftUserFilter
    {
        return $this->where('user_id', $userId);
    }

    public function status(string $status): ShiftUserFilter
    {
        return $this->where('status', $status);
    }
}
