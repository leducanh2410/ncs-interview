<?php

namespace App\Http\Requests\Manage\Salary;

use App\Enums\SalaryProcessStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalaryProcessIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['nullable', 'numeric'],
            'month' => ['nullable', 'string', 'date_format:Y-m'],
            'status' => ['nullable', 'string', Rule::in(SalaryProcessStatus::values())],
        ];
    }
}
