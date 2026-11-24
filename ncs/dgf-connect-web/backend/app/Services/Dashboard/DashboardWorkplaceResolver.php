<?php

namespace App\Services\Dashboard;

use App\Models\User;

class DashboardWorkplaceResolver
{
    private const PERMISSION_DEMAND = 'shift.list';

    private const PERMISSION_RELIABILITY = 'work_history.list';

    private ?array $demand = null;

    private ?array $reliability = null;

    private bool $demandResolved = false;

    private bool $reliabilityResolved = false;

    public function __construct(private readonly User $user) {}

    public function user(): User
    {
        return $this->user;
    }

    public function demand(): ?array
    {
        if (!$this->demandResolved) {
            $this->demand = $this->user->getWorkplaceIdsByPermission(self::PERMISSION_DEMAND);
            $this->demandResolved = true;
        }

        return $this->demand;
    }

    public function reliability(): ?array
    {
        if (!$this->reliabilityResolved) {
            $shiftIds = $this->user->getWorkplaceIdsByPermission(self::PERMISSION_DEMAND);
            $workHistoryIds = $this->user->getWorkplaceIdsByPermission(self::PERMISSION_RELIABILITY);
            $this->reliability = $this->intersect($shiftIds, $workHistoryIds);
            $this->reliabilityResolved = true;
        }

        return $this->reliability;
    }

    public function intersectWithRequested(?array $set, ?array $requested): ?array
    {
        return $this->intersect($set, $requested);
    }

    public function isEmpty(?array $set): bool
    {
        return is_array($set) && count($set) === 0;
    }

    private function intersect(?array $a, ?array $b): ?array
    {
        if ($a === null && $b === null) {
            return null;
        }

        if ($a === null) {
            return array_values(array_unique($b));
        }

        if ($b === null) {
            return array_values(array_unique($a));
        }

        return array_values(array_unique(array_intersect($a, $b)));
    }
}
