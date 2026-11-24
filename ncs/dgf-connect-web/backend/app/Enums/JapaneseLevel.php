<?php

namespace App\Enums;

enum JapaneseLevel: string
{
    use EnumTrait;

    case N1 = 'n1';
    case N2 = 'n2';
    case N3 = 'n3';
    case N4 = 'n4';
    case N5 = 'n5';
    case None = 'none';
}
