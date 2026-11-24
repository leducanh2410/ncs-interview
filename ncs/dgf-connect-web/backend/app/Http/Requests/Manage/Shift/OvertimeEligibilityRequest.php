<?php

namespace App\Http\Requests\Manage\Shift;

use Illuminate\Foundation\Http\FormRequest;

class OvertimeEligibilityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'overtime_minutes' => ['required', 'integer', 'min:1'],
        ];
    }
}
