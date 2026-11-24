<?php

namespace App\Enums;

enum BankAccountType: string
{
    use EnumTrait;

    case Ordinary = 'ordinary';

    case Current = 'current';

    case Savings = 'savings';

    case TimeDeposit = 'time_deposit';
}
