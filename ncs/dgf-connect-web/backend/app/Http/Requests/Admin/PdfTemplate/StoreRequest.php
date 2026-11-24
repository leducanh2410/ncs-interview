<?php

namespace App\Http\Requests\Admin\PdfTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('pdf_templates', 'name')->whereNull('deleted_at')],
            'file' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }
}
