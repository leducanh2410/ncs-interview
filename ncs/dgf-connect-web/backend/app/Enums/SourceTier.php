<?php

namespace App\Enums;

enum SourceTier: string
{
    use EnumTrait;

    case Newcomer = 'newcomer';
    case Regular = 'regular';
    case Veteran = 'veteran';

    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Night = 'night';

    public static function seniorityTiers(): array
    {
        return [self::Newcomer, self::Regular, self::Veteran];
    }

    public static function worktimeTiers(): array
    {
        return [self::Morning, self::Afternoon, self::Night];
    }
}
