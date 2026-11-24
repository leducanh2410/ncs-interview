<?php

namespace App\Enums;

enum ReconciliationMatchMethod: string
{
    use EnumTrait;

    case ExactKana = 'exact_kana';
    case ExactShort = 'exact_short';
    case StaffNo = 'staff_no';
    case Manual = 'manual';
}
