<?php

namespace App\Http\Requests\Manage\Shift;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalaryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'salary_per_hour' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
