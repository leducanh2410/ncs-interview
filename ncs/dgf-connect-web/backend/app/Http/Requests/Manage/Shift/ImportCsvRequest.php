<?php

namespace App\Http\Requests\Manage\Shift;

use App\Enums\Encoding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportCsvRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'encoding' => ['required', 'string', Rule::in(Encoding::values())],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }
}
