<?php

namespace App\Http\Requests\Admin\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConfigRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'max_hour_per_week' => ['required', 'integer', 'min:0'],
            'max_shift_per_day' => ['required', 'integer', 'min:0'],
            'max_shift_per_day_start_date' => ['nullable', 'dateFormat:Y-m-d', 'before:max_shift_per_day_expiry_date'],
            'max_shift_per_day_expiry_date' => ['nullable', 'dateFormat:Y-m-d', 'after:max_shift_per_day_start_date'],
            'max_hour_per_week_start_date' => ['nullable', 'dateFormat:Y-m-d', 'before:max_hour_per_week_expiry_date'],
            'max_hour_per_week_expiry_date' => ['nullable', 'dateFormat:Y-m-d', 'after:max_hour_per_week_start_date'],
        ];
    }
}
