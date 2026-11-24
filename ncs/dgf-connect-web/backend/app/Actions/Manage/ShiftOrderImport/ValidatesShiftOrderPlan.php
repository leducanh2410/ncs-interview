<?php

namespace App\Actions\Manage\ShiftOrderImport;

trait ValidatesShiftOrderPlan
{
    private const SPOT_KEYWORDS = ['spot', 'スポット'];

    private function isSpotPosition(string $name): bool
    {
        $lowered = mb_strtolower($name);
        foreach (self::SPOT_KEYWORDS as $keyword) {
            if (str_contains($lowered, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function shiftTimeKey(string $startTime, string $endTime): string
    {
        return mb_substr($startTime, 0, 5) . '-' . mb_substr($endTime, 0, 5);
    }
}
