<?php

namespace App\ModelFilters;

use Illuminate\Database\Eloquent\Builder;

class ExamResultFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    /**
     * Filter by exam title
     */
    public function title(string $title): ExamResultFilter
    {
        return $this->whereHas('exam', function (Builder $query) use ($title) {
            $query->whereHas('translations', function (Builder $translationQuery) use ($title) {
                $translationQuery->where('field', 'title')->whereLike('value', $title);
            });
        });
    }

    /**
     * Filter by user ID
     */
    public function user(int $userId): ExamResultFilter
    {
        return $this->where('user_id', $userId);
    }

    /**
     * Filter by completed_at date range
     */
    public function completedAt(string|array $dates): ExamResultFilter
    {
        // If a single date is provided (e.g., '2025-10-20'), interpret it as the full day
        if (!is_array($dates)) {
            $start = $dates . ' 00:00:00';
            $end = $dates . ' 23:59:59';
            $this->where('completed_at', '>=', $start);
            $this->where('completed_at', '<=', $end);

            return $this;
        }

        return $this->applyDateFilter($dates, 'completed_at');
    }

    public function occurrence(int $occurrence): ExamResultFilter
    {
        return $this->where('occurrence', $occurrence);
    }

    /**
     * Filter by status
     */
    public function status(string $status): ExamResultFilter
    {
        if ($status === self::ALL_VALUE) {
            return $this;
        }

        return $this->where('status', $status);
    }
}
