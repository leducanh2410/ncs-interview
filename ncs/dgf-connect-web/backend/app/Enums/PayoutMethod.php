<?php

namespace App\Enums;

enum PayoutMethod: string
{
    use EnumTrait;

    case BankTransfer = 'bank_transfer';

    case SevenBankAtm = 'seven_bank_atm';
}
