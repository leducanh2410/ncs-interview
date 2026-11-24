<?php

namespace App\Exceptions;

use RuntimeException;

class RuleBasedGroupTooBroadException extends RuntimeException
{
    public function __construct(
        public readonly int $matchCount,
        public readonly int $activeUserCount,
        public readonly int $thresholdPercent
    ) {
        parent::__construct('Rule-based group matches too many active users.');
    }

    public function coveragePercent(): float
    {
        if ($this->activeUserCount <= 0) {
            return 0.0;
        }

        return round(($this->matchCount / $this->activeUserCount) * 100, 1);
    }

    public function errors(): array
    {
        return [
            'predicate' => [
                sprintf(
                    'This rule would match %d of %d active users (%s%%), which exceeds the %d%% cap. Please tighten the rule.',
                    $this->matchCount,
                    $this->activeUserCount,
                    $this->coveragePercent(),
                    $this->thresholdPercent
                ),
            ],
        ];
    }
}
