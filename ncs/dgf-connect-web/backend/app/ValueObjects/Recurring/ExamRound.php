<?php

namespace App\ValueObjects\Recurring;

use App\Models\ExamResult;
use Illuminate\Support\Collection;

readonly class ExamRound
{
    /**
     * @param Collection<int, ExamResult> $roundResults
     */
    public function __construct(public ?RecurringTrigger $activeTrigger, public Collection $roundResults) {}
}
