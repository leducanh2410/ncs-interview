<?php

namespace App\ModelFilters;

class WorkTimeReportFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    public function month(string $month): WorkTimeReportFilter
    {
        return $this->where('month', $month);
    }

    public function user($userId): WorkTimeReportFilter
    {
        return $this->where('user_id', $userId);
    }
}
