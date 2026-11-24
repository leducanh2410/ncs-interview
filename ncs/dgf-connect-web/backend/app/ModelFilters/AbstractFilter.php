<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

abstract class AbstractFilter extends ModelFilter
{
    const ALL_VALUE = 'all';

    const ALL_VALUE_INT = 0;

    protected function applyDateFilter(array|string $dates, string $field): self
    {
        if (empty($dates)) {
            return $this;
        }
        if (!is_array($dates)) {
            $dates = [$dates];
        }
        $this->where($field, '>=', $dates[0]);
        if (isset($dates[1])) {
            $this->where($field, '<=', $dates[1]);
        }

        return $this;
    }
}
