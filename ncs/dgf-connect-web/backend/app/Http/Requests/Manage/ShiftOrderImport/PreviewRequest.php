<?php

namespace App\Http\Requests\Manage\ShiftOrderImport;

use Illuminate\Foundation\Http\FormRequest;

class PreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['bail', 'required', 'file', 'mimes:xlsx', 'max:10240'],
            'month' => ['nullable', 'string', 'date_format:Y-m'],
        ];
    }
}
