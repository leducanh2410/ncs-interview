<?php

namespace App\Actions\Manage\ShiftOrderImport;

use App\Imports\ShiftOrderImport;
use Maatwebsite\Excel\Facades\Excel;

trait ReadsShiftOrderWorkbook
{
    private function readWorkbook(mixed $file, ?string $disk = null): ShiftOrderImport
    {
        $import = new ShiftOrderImport();
        Excel::import($import, $file, $disk);

        return $import;
    }
}
