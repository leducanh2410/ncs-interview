<?php

namespace App\ValueObjects\Recurring;

readonly class CountableShift
{
    public function __construct(public int $workplaceId, public ShiftPosition $position, public bool $worked) {}
}
