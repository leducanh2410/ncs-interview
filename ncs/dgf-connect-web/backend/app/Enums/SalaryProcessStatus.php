<?php

namespace App\Enums;

enum SalaryProcessStatus: string
{
    use EnumTrait;

    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
}
