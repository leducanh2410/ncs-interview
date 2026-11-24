<?php

namespace App\Services\Reconciliation;

use RuntimeException;

class ReconciliationFormatException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly mixed $details = null)
    {
        parent::__construct($reason);
    }
}
