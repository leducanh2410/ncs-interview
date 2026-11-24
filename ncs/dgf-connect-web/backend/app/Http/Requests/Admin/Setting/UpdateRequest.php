<?php

namespace App\Http\Requests\Admin\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'max_hour_per_week' => ['required', 'integer', 'min:0'],
            'max_shift_per_day' => ['required', 'integer', 'min:0'],
            'salary_per_hour' => ['required', 'integer', 'min:0'],
            'login_url' => ['required', 'url', 'max:255', 'regex:/^https?:\/\//i'],
        ];
    }

    public function attributes(): array
    {
        return [
            'login_url' => 'url',
        ];
    }
}
