<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    use EnumTrait;

    case Late = 'late';

    case EarlyLeave = 'early_leave';

    case Absent = 'absent';
}
