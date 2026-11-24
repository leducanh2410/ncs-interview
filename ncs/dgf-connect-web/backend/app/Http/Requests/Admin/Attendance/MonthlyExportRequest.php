<?php

namespace App\Http\Requests\Admin\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class MonthlyExportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'month' => ['required', 'string', 'date_format:Y-m'],
            // Empty means every admin with attendance that month.
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
