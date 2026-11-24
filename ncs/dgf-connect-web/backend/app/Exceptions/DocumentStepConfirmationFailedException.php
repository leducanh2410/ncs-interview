<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Thrown to abort a multi-step submit part way through.
 *
 * Returning an error code from the transaction closure would commit the steps applied
 * before the failing one — the opposite of what submitting together is for — so the
 * failure has to travel as an exception and be turned back into a response outside.
 */
class DocumentStepConfirmationFailedException extends RuntimeException
{
    public function __construct(public readonly string $stepKey, public readonly ErrorCode $errorCode)
    {
        parent::__construct('Document step confirmation failed: ' . $stepKey);
    }
}
