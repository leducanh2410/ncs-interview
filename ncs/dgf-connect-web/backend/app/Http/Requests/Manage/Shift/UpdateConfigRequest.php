<?php

namespace App\Http\Requests\Manage\Shift;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConfigRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fixed_total' => ['required', 'integer', 'min:0'],
            'temp_total' => ['required', 'integer', 'min:0'],
            'max_registered_temp' => ['nullable', 'integer', 'min:0'],
            'break_minutes' => ['nullable', 'integer', 'min:0'],
            'is_overtime_by_duration' => ['nullable', 'boolean'],
        ];
    }
}
