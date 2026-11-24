<?php

namespace App\Http\Requests\Manage\WorkHistory;

use Illuminate\Foundation\Http\FormRequest;

class MonthIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'string', 'date_format:Y-m'],
            'workplace_id' => ['nullable', 'numeric'],
            'user_id' => ['nullable', 'numeric'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
