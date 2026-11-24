<?php

namespace App\Http\Requests\Manage\Salary;

use Illuminate\Foundation\Http\FormRequest;

class CreateSalaryProcessRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'month' => ['required', 'string', 'date_format:Y-m'],
            'workplace_id' => ['required', 'integer', 'exists:workplaces,id'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
