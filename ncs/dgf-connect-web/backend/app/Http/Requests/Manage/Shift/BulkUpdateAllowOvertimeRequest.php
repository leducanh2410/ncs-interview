<?php

namespace App\Http\Requests\Manage\Shift;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateAllowOvertimeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'shift_user_ids' => ['present', 'array'],
            'shift_user_ids.*' => ['integer'],
        ];
    }
}
