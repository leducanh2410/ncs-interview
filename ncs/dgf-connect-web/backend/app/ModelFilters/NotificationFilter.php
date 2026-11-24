<?php

namespace App\ModelFilters;

class NotificationFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    public function dates($dates): NotificationFilter
    {
        return $this->applyDateFilter($dates, 'scheduled_at');
    }

    public function status(string $status): NotificationFilter
    {
        if ($status === self::ALL_VALUE) {
            return $this;
        }

        return $this->whereLike('status', $status);
    }

    public function type(string $type): NotificationFilter
    {
        if ($type === self::ALL_VALUE) {
            return $this;
        }

        return $this->whereLike('type', $type);
    }
}
