<?php

namespace App\Enums;

enum CompanyHolidaySource: string
{
    use EnumTrait;

    case Library = 'library';
    case Manual = 'manual';
}
