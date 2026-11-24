<?php

namespace App\Enums;

enum StressLevel: string
{
    use EnumTrait;

    case Low = 'low';
    case High = 'high';
}
