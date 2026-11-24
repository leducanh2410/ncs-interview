<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

class LogFilter extends ModelFilter
{
    /**
     * Filter by start date.
     */
    public function startDate(string $date): LogFilter
    {
        return $this->whereDate('created_at', '>=', $date);
    }

    /**
     * Filter by end date.
     */
    public function endDate(string $date): LogFilter
    {
        return $this->whereDate('created_at', '<=', $date);
    }

    /**
     * Filter by role.
     */
    public function role(string $role): LogFilter
    {
        return $this->whereJsonContains('actor_role', $role);
    }
}
