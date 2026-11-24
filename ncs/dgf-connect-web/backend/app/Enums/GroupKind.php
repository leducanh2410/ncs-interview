<?php

namespace App\Enums;

enum GroupKind: string
{
    use EnumTrait;

    case WorkplaceCurrent = 'workplace_current';
    case WorkplaceSeniority = 'workplace_seniority';
    case Worktime = 'worktime';
    case FrequentUrgentWorker = 'frequent_urgent_worker';
    case Manual = 'manual';
    case RuleBased = 'rule_based';

    public static function systemKinds(): array
    {
        return [self::WorkplaceCurrent, self::WorkplaceSeniority, self::Worktime, self::FrequentUrgentWorker];
    }

    public static function systemValues(): array
    {
        return array_column(self::systemKinds(), 'value');
    }

    public function isSystem(): bool
    {
        return in_array($this, self::systemKinds(), true);
    }

    public function isAdmin(): bool
    {
        return $this === self::Manual || $this === self::RuleBased;
    }
}
