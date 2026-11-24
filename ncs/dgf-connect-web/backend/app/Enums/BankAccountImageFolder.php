<?php

namespace App\Enums;

enum BankAccountImageFolder: string
{
    use EnumTrait;

    case Current = 'current';

    case Old = 'old';
}
