<?php

namespace App\Services\Reconciliation;

interface DetectsReconciliationFormat
{
    public function matchesFormat(string $absolutePath): bool;
}
