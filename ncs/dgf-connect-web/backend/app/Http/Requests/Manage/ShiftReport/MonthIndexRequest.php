<?php

namespace App\Http\Requests\Manage\ShiftReport;

use Illuminate\Foundation\Http\FormRequest;

class MonthIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'string', 'date_format:Y-m'],
            'user_id' => ['nullable', 'numeric'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
