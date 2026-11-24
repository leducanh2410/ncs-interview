<?php

namespace App\Http\Requests\Admin\PdfTemplate;

use Illuminate\Foundation\Http\FormRequest;

class ReUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }
}
