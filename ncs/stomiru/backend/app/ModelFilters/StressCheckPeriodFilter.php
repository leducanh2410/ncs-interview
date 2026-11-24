<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

class StressCheckPeriodFilter extends ModelFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    public function title($title)
    {
        return $this->where('title', 'LIKE', "%$title%");
    }

    public function status($status)
    {
        return $this->where('status', $status);
    }

    public function year($year)
    {
        return $this->where('year', $year);
    }
}
