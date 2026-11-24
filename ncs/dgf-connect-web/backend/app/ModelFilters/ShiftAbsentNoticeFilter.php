<?php

namespace App\ModelFilters;

use Illuminate\Database\Query\JoinClause;

class ShiftAbsentNoticeFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    public function dateFrom(string $date): self
    {
        return $this->joinShiftTables()->whereDate('shifts.date', '>=', $date);
    }

    public function dateTo(string $date): self
    {
        return $this->joinShiftTables()->whereDate('shifts.date', '<=', $date);
    }

    public function workplace(int $workplaceId): self
    {
        if ($workplaceId === self::ALL_VALUE_INT) {
            return $this;
        }

        return $this->joinShiftTables()->where('shifts.workplace_id', $workplaceId);
    }

    public function status(string $status): self
    {
        return $this->where('shift_absent_notices.status', $status);
    }

    private function joinShiftTables(): self
    {
        if (!$this->hasJoin('shift_users')) {
            $this->join('shift_users', function (JoinClause $join) {
                $join->on('shift_users.id', '=', 'shift_absent_notices.shift_user_id');
            });
        }

        if (!$this->hasJoin('shifts')) {
            $this->join('shifts', function (JoinClause $join) {
                $join->on('shifts.id', '=', 'shift_users.shift_id');
            });
        }

        $this->select('shift_absent_notices.*');

        return $this;
    }

    private function hasJoin(string $table): bool
    {
        return collect($this->query->getQuery()->joins ?? [])->contains(
            fn(JoinClause $join) => $join->table === $table
        );
    }
}
