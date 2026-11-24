<?php

namespace App\Http\Requests\Manage\Salary;

use App\Enums\Encoding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportDeductionCsvRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'encoding' => ['required', 'string', Rule::in(Encoding::values())],
            'month' => ['required', 'string', 'date_format:Y-m'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }
}
