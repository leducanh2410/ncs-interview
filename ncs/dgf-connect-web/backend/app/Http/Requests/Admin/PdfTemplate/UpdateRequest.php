<?php

namespace App\Http\Requests\Admin\PdfTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $templateId = $this->route('template')?->id ?? $this->route('template');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('pdf_templates', 'name')->whereNull('deleted_at')->ignore($templateId),
            ],
        ];
    }
}
