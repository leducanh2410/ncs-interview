<?php

namespace App\Enums;

enum ScaleLevel: string
{
    use EnumTrait;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
