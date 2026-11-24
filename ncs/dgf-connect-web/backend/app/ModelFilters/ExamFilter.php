<?php

namespace App\ModelFilters;

use App\Enums\ExamType;
use App\Models\Exam;
use Illuminate\Database\Eloquent\Builder;

class ExamFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    /**
     * A `null` `type` column (legacy rows) is treated as `assessment`
     * (`Exam::type` accessor), so filtering for `assessment` must also match
     * `NULL` rows, not just the literal string.
     */
    public function type(string $type): ExamFilter
    {
        if ($type === self::ALL_VALUE) {
            return $this;
        }

        if ($type === ExamType::Assessment->value) {
            return $this->where(function (Builder $query) use ($type) {
                $query->whereNull('type')->orWhere('type', $type);
            });
        }

        return $this->where('type', $type);
    }

    public function title(string $title): ExamFilter
    {
        return $this->whereHas('translations', function (Builder $query) use ($title) {
            $query->where('field', 'title')->whereLike('value', $title);
        });
    }

    public function isRequired(string $isRequired): ExamFilter
    {
        if ($isRequired === self::ALL_VALUE) {
            return $this;
        }

        return $this->where('is_required', $isRequired ? 1 : 0);
    }

    public function workplace(int $workplaceId): ExamFilter
    {
        if ($workplaceId === self::ALL_VALUE_INT) {
            return $this;
        }

        return $this->whereHas('workplaces', function (Builder $query) use ($workplaceId) {
            $query->where('workplaces.id', $workplaceId);
        });
    }

    public function endAt(string $date): ExamFilter
    {
        return $this->whereDate('end_at', $date);
    }

    /**
     * Filter by derived status:
     * - scheduled: exam has not yet started (start_at > now)
     * - active:    exam is currently running (start_at <= now and end_at >= now or null)
     * - ended:     exam has finished (end_at < now)
     */
    public function status(string $status): ExamFilter
    {
        $now = now();

        return match ($status) {
            Exam::STATUS_SCHEDULED => $this->whereNotNull('start_at')->where('start_at', '>', $now),
            Exam::STATUS_ACTIVE => $this->where(function (Builder $query) use ($now) {
                $query->whereNull('start_at')->orWhere('start_at', '<=', $now);
            })->where(function (Builder $query) use ($now) {
                $query->where('is_required', true)->orWhere(function (Builder $optionalExamQuery) use ($now) {
                    $optionalExamQuery->whereNull('end_at')->orWhere('end_at', '>=', $now);
                });
            }),
            Exam::STATUS_ENDED => $this->where('is_required', false)
                ->whereNotNull('end_at')
                ->where('end_at', '<', $now),
            default => $this,
        };
    }
}
