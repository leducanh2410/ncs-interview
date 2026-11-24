<?php

namespace App\ValueObjects\Recurring;

readonly class RecurringTrigger
{
    public function __construct(
        public int $workplaceId,
        public ShiftPosition $trigger,
        public ShiftPosition $completionAnchor,
        public int $occurrence
    ) {}
}
