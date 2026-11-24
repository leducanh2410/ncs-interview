<?php

namespace App\Support\Dashboard;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Inclusive [start, end] date range used by dashboard analytics actions.
 *
 * Two factories are supported:
 *   - fromWindow(N) maps the legacy `?window=N` query into the inclusive
 *     range [today-N .. today-1] (Tokyo timezone).
 *   - fromExplicit(start, end) accepts caller-provided dates (validated by the
 *     controller) and treats them as an inclusive [start..end] range.
 *
 * Both factories expose getPriorRange() — a same-length inclusive range
 * immediately preceding the current one — so delta KPIs work uniformly.
 */
final class DashboardDateRange
{
    public const TIMEZONE = 'Asia/Tokyo';

    public const MAX_DAYS = 365;

    private function __construct(private readonly string $start, private readonly string $end) {}

    public static function fromWindow(int $window, ?Carbon $today = null): self
    {
        $today ??= Carbon::now(self::TIMEZONE)->startOfDay();
        $end = $today->copy()->subDay()->toDateString();
        $start = $today->copy()->subDays($window)->toDateString();

        return new self($start, $end);
    }

    public static function fromExplicit(string $start, string $end): self
    {
        $startDate = Carbon::createFromFormat('Y-m-d', $start, self::TIMEZONE);
        $endDate = Carbon::createFromFormat('Y-m-d', $end, self::TIMEZONE);

        if (!$startDate || !$endDate) {
            throw new InvalidArgumentException('Invalid date format; expected Y-m-d.');
        }

        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException('end_date must be on or after start_date.');
        }

        return new self($startDate->toDateString(), $endDate->toDateString());
    }

    public function getStart(): string
    {
        return $this->start;
    }

    public function getEnd(): string
    {
        return $this->end;
    }

    /**
     * Number of days in the inclusive range (e.g. start==end → 1).
     */
    public function getDays(): int
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->start, self::TIMEZONE);
        $end = Carbon::createFromFormat('Y-m-d', $this->end, self::TIMEZONE);

        return $start->diffInDays($end) + 1;
    }

    /**
     * Same-length inclusive range immediately preceding this one.
     */
    public function getPriorRange(): self
    {
        $days = $this->getDays();
        $start = Carbon::createFromFormat('Y-m-d', $this->start, self::TIMEZONE);

        $priorEnd = $start->copy()->subDay()->toDateString();
        $priorStart = $start->copy()->subDays($days)->toDateString();

        return new self($priorStart, $priorEnd);
    }

    /**
     * Ordered list of YYYY-MM-DD dates covering [start..end] inclusive.
     *
     * @return string[]
     */
    public function eachDate(): array
    {
        $dates = [];
        $current = Carbon::createFromFormat('Y-m-d', $this->start, self::TIMEZONE);
        $end = Carbon::createFromFormat('Y-m-d', $this->end, self::TIMEZONE);

        while ($current->lte($end)) {
            $dates[] = $current->toDateString();
            $current->addDay();
        }

        return $dates;
    }
}
