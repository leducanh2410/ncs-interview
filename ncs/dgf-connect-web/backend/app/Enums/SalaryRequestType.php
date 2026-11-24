<?php

namespace App\Enums;

enum SalaryRequestType: string
{
    use EnumTrait;

    case Transfer = 'transfer';
    case Cash = 'cash';
}
