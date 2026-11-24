<?php

namespace App\Enums;

enum Gender: string
{
    use EnumTrait;

    case Male = 'male';
    case Female = 'female';
    case Other = 'other';

    public static function toCrossStaffValue(?string $value): string
    {
        return match ($value) {
            self::Male->value => '2',
            self::Female->value => '1',
            default => '3',
        };
    }

    public static function fromCrossStaffValue(?string $value): string
    {
        return match ($value) {
            '1' => self::Female->value,
            '2' => self::Male->value,
            default => self::Other->value,
        };
    }
}
