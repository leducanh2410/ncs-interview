<?php

namespace App\Enums;

enum AdminAttendanceEventType: string
{
    use EnumTrait;

    case CheckIn = 'check_in';
    case CheckOut = 'check_out';
}
