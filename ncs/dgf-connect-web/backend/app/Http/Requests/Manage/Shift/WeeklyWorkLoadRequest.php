<?php

namespace App\Http\Requests\Manage\Shift;

use Illuminate\Foundation\Http\FormRequest;

class WeeklyWorkLoadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'max:2000'],
            'user_ids.*' => ['integer'],
        ];
    }
}
