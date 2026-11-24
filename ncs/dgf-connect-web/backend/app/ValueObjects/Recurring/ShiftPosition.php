<?php

namespace App\ValueObjects\Recurring;

use Carbon\CarbonImmutable;

readonly class ShiftPosition
{
    public function __construct(
        public ?int $shiftUserId,
        public CarbonImmutable $date,
        public CarbonImmutable $startAt,
        public int $sortId
    ) {}

    public function compare(ShiftPosition $other): int
    {
        return [$this->date->toDateString(), $this->startAt->getTimestamp(), $this->sortId] <=> [
            $other->date->toDateString(),
            $other->startAt->getTimestamp(),
            $other->sortId,
        ];
    }

    public function isAfter(ShiftPosition $other): bool
    {
        return $this->compare($other) > 0;
    }

    public static function later(ShiftPosition $first, ?ShiftPosition $second): ShiftPosition
    {
        if ($second === null) {
            return $first;
        }

        return $second->isAfter($first) ? $second : $first;
    }
}
