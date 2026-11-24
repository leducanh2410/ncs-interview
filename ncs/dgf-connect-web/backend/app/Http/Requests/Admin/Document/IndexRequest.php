<?php

namespace App\Http\Requests\Admin\Document;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['bail', 'nullable', 'string', 'max:255'],
            'workplace_id' => ['bail', 'nullable', 'integer'],
            'dates' => ['bail', 'nullable', 'array'],
            'dates.*' => ['bail', 'date'],
            'id' => ['bail', 'nullable', 'integer'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
