<?php

namespace App\Enums;

enum VerificationStatus: string
{
    use EnumTrait;

    case Pending = 'pending';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Error = 'error';
}
