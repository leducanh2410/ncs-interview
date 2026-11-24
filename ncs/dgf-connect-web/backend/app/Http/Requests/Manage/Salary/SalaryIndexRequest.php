<?php

namespace App\Http\Requests\Manage\Salary;

use Illuminate\Foundation\Http\FormRequest;

class SalaryIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'numeric'],
            'month' => ['nullable', 'string', 'date_format:Y-m'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
