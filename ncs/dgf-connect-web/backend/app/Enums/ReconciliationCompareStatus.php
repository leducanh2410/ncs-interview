<?php

namespace App\Enums;

enum ReconciliationCompareStatus: string
{
    use EnumTrait;

    case NotComparable = 'not_comparable';
    case Matched = 'matched';
    case Differs = 'differs';
}
