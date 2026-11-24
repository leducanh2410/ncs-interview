<?php

namespace App\Http\Requests\Manage\Shift;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAllowOvertimeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'is_overtime_allowed' => ['required', 'boolean'],
        ];
    }
}
