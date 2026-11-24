<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    use EnumTrait;

    case Active = 'active';
    case OnLeave = 'on_leave';
    case Retired = 'retired';
}
