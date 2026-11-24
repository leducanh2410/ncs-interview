<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

class BankAccountStateException extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly int $status = 409,
        ?string $message = null
    ) {
        parent::__construct($message ?? $errorCode->value);
    }
}
