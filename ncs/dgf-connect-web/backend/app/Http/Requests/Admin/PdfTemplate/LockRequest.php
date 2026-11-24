<?php

namespace App\Http\Requests\Admin\PdfTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LockRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(['editing'])],
        ];
    }
}
