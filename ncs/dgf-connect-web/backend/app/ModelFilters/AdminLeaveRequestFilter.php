<?php

namespace App\ModelFilters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AdminLeaveRequestFilter extends AbstractFilter
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
        return $this->applyDateRange($date, $this->input('date_to'));
    }

    public function dateTo(string $date): self
    {
        // dateFrom already covers the pair when both are supplied; this only handles a lone
        // date_to so the filter is not silently ignored.
        if ($this->input('date_from')) {
            return $this;
        }

        return $this->applyDateRange(null, $date);
    }

    /**
     * Named `requester`, not `requesterId`: EloquentFilter strips the `_id` suffix from input
     * keys before looking up the method, so `requester_id` resolves here.
     */
    public function requester(int $requesterId): self
    {
        if ($requesterId === self::ALL_VALUE_INT) {
            return $this;
        }

        return $this->where('requester_id', $requesterId);
    }

    public function status(string $status): self
    {
        if ($status === self::ALL_VALUE) {
            return $this;
        }

        return $this->where('status', $status);
    }

    public function type(string $type): self
    {
        if ($type === self::ALL_VALUE) {
            return $this;
        }

        return $this->where('type', $type);
    }

    /**
     * The date filter is dual-mode: a request with a leave period is matched on that period
     * overlapping the range, while a dateless expense claim is matched on its submission date
     * instead. Without the second arm, the default current-month filter would hide every expense
     * claim submitted without dates — the exact trap this filter exists to avoid.
     *
     * Both arms live in one `where` group so the whole thing stays a single AND-term and composes
     * correctly with the requester, type, and status filters.
     */
    private function applyDateRange(?string $from, ?string $to): self
    {
        return $this->where(function (Builder $query) use ($from, $to) {
            $query
                ->where(function (Builder $periodQuery) use ($from, $to) {
                    $periodQuery->whereNotNull('start_date');
                    // Overlap, not containment: a request running 25 Aug – 5 Sep must show up
                    // under a September filter even though it started in August.
                    if ($to !== null) {
                        $periodQuery->whereDate('start_date', '<=', $to);
                    }
                    if ($from !== null) {
                        $periodQuery->whereDate('end_date', '>=', $from);
                    }
                })
                ->orWhere(function (Builder $submittedQuery) use ($from, $to) {
                    $submittedQuery->whereNull('start_date');
                    if ($from !== null) {
                        $submittedQuery->where('created_at', '>=', Carbon::parse($from)->startOfDay());
                    }
                    if ($to !== null) {
                        $submittedQuery->where('created_at', '<=', Carbon::parse($to)->endOfDay());
                    }
                });
        });
    }
}
