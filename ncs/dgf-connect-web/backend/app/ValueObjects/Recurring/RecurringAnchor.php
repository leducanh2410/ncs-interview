<?php

namespace App\ValueObjects\Recurring;

readonly class RecurringAnchor
{
    public function __construct(public ?ShiftPosition $position, public int $lastOccurrence) {}
}
