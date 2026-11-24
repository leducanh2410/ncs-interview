<?php

namespace App\Support\Dashboard;

class DashboardThresholds
{
    public const CAPACITY_UTILIZATION = [
        'critical' => 0.9,
        'tight' => 0.75,
    ];

    /**
     * Classify a workplace/date capacity row.
     *
     * @return array{tag: 'critical'|'tight'|'healthy'|null, reason?: 'no_pool'}
     */
    public static function tagFor(?int $activePool, int $assigned): array
    {
        if ($activePool === null) {
            return ['tag' => null];
        }

        if ($activePool === 0) {
            if ($assigned > 0) {
                return ['tag' => 'critical', 'reason' => 'no_pool'];
            }

            return ['tag' => 'healthy'];
        }

        $utilization = $assigned / $activePool;

        if ($utilization >= self::CAPACITY_UTILIZATION['critical']) {
            return ['tag' => 'critical'];
        }

        if ($utilization >= self::CAPACITY_UTILIZATION['tight']) {
            return ['tag' => 'tight'];
        }

        return ['tag' => 'healthy'];
    }
}
