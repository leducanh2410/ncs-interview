<?php

namespace App\Helpers;

class NumericHelper
{
    public static function bcAddMany(array $numbers, int $scale = 0): string
    {
        $sum = '0';
        foreach ($numbers as $number) {
            $sum = bcadd($sum, (string) $number, $scale);
        }

        return $sum;
    }

    public static function bcSubMany(string $number, array $numbers, int $scale = 0): string
    {
        $result = $number;
        foreach ($numbers as $number) {
            $result = bcsub($result, (string) $number, $scale);
        }

        return $result;
    }
}
