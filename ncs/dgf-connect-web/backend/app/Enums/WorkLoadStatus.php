<?php

namespace App\Enums;

enum WorkLoadStatus: string
{
    use EnumTrait;

    case Normal = 'normal';
    case Near = 'near';
    case Over = 'over';

    public const NEAR_LIMIT_RATIO = 0.9;

    private const FLOAT_TOLERANCE = 0.000001;

    public static function fromHours(float $hours, int $maxHours): self
    {
        return match (true) {
            $hours - $maxHours > self::FLOAT_TOLERANCE => self::Over,
            $hours - $maxHours * self::NEAR_LIMIT_RATIO > -self::FLOAT_TOLERANCE => self::Near,
            default => self::Normal,
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Normal => 0,
            self::Near => 1,
            self::Over => 2,
        };
    }
}
