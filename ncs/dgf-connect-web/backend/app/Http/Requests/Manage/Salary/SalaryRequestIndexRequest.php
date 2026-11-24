<?php

namespace App\Http\Requests\Manage\Salary;

use Illuminate\Foundation\Http\FormRequest;

class SalaryRequestIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'numeric'],
            'status' => ['nullable', 'string'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
