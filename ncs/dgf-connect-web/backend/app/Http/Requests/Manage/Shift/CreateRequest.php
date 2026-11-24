<?php

namespace App\Http\Requests\Manage\Shift;

use Illuminate\Foundation\Http\FormRequest;

class CreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'dateFormat:Y-m-d', 'after_or_equal:today'],
            'workplace_shift_id' => ['required', 'integer'],
            'fixed_total' => ['required', 'integer', 'min:0'],
            'temp_total' => ['required', 'integer', 'min:0'],
            'max_registered_temp' => ['nullable', 'integer', 'min:0'],
            'break_minutes' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
