<?php

namespace App\Http\Requests\Admin\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class MyHistoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'dates' => ['nullable', 'array'],
            'dates.*' => ['date'],
            'date' => ['sometimes', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
