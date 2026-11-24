<?php

namespace App\Services\Reconciliation;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class HeaderRowsReadFilter implements IReadFilter
{
    public function __construct(private readonly int $maxRow) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row <= $this->maxRow;
    }
}
