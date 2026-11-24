<?php

namespace App\Http\Requests\Manage\ShiftReport;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'dates' => ['nullable', 'array'],
            'workplace_id' => ['nullable', 'numeric'],
            'user_id' => ['nullable', 'numeric'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
