<?php

namespace App\Http\Requests\Admin\FixedScheduleChangeConfig;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'is_enabled' => ['bail', 'required', 'boolean'],
            'deadline' => ['bail', 'nullable', 'date'],
        ];
    }
}
