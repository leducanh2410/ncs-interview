<?php

namespace App\Enums;

enum Encoding: string
{
    use EnumTrait;

    case UTF8 = 'utf-8';

    case ShiftJIS = 'shift-jis';
}
