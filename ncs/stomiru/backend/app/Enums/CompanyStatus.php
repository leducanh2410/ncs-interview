<?php

namespace App\Enums;

enum CompanyStatus: string
{
    use EnumTrait;

    case Pending = 'pending';
    case Active = 'active';
    case Cancelled = 'cancelled';
}
