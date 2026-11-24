<?php

namespace App\Http\Requests\Admin\ExamResult;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['bail', 'nullable', 'string', 'max:255'],
            'user_id' => ['bail', 'nullable', 'integer'],
            'status' => ['bail', 'nullable', 'string', 'max:255'],
            'completed_at' => ['bail', 'nullable', 'date'],
            'occurrence' => ['bail', 'nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
