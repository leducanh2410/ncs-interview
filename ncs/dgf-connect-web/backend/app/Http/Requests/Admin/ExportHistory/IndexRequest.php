<?php

namespace App\Http\Requests\Admin\ExportHistory;

use App\Enums\ExportBatchSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'in:pdf,csv,work_history'],
            'template_id' => ['nullable', 'integer', 'exists:pdf_templates,id'],
            'source' => ['nullable', 'string', Rule::in(ExportBatchSource::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
