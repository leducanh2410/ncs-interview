<?php

namespace App\Http\Requests\Admin\PdfExport;

use Illuminate\Foundation\Http\FormRequest;

class ExcelExportRequest extends FormRequest
{
    public function rules(): array
    {
        $maxFileSizeKb = max((int) config('pdf_export.excel_max_file_size_kb', 5120), 1);

        return [
            'template_id' => ['required', 'integer', 'exists:pdf_templates,id'],
            'file' => [
                'bail',
                'required',
                'file',
                'extensions:xlsx,xls,csv',
                'mimes:xlsx,xls,csv,txt',
                "max:{$maxFileSizeKb}",
            ],
        ];
    }
}
