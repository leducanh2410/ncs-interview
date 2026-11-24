<?php

namespace App\ValueObjects;

use App\Enums\SourceTier;

readonly class GroupTierConfig
{
    public function __construct(
        public int $workplaceSeniorityNewcomerMax = 4,
        public int $workplaceSeniorityRegularMax = 29,
        public int $worktimeMorningBefore = 12,
        public int $worktimeAfternoonBefore = 18
    ) {}

    public function workplaceSeniorityTier(int $completedShifts): SourceTier
    {
        if ($completedShifts <= $this->workplaceSeniorityNewcomerMax) {
            return SourceTier::Newcomer;
        }

        if ($completedShifts <= $this->workplaceSeniorityRegularMax) {
            return SourceTier::Regular;
        }

        return SourceTier::Veteran;
    }

    public function worktimeTier(int $hour): SourceTier
    {
        if ($hour < $this->worktimeMorningBefore) {
            return SourceTier::Morning;
        }

        if ($hour < $this->worktimeAfternoonBefore) {
            return SourceTier::Afternoon;
        }

        return SourceTier::Night;
    }
}
