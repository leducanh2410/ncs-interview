<?php

namespace App\Enums;

enum ExportBatchSource: string
{
    case Manual = 'manual';
    case Excel = 'excel';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
