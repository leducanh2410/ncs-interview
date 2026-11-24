<?php

namespace App\Actions\Admin\PdfExport\Excel;

use App\Actions\ActionResult;

class GetExcelExportLimitsAction
{
    public function __invoke(): ActionResult
    {
        return new ActionResult(
            data: [
                'max_rows' => max((int) config('pdf_export.excel_max_rows', 5000), 1),
                'max_file_size_kb' => max((int) config('pdf_export.excel_max_file_size_kb', 5120), 1),
            ]
        );
    }
}
