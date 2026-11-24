<?php

namespace App\Http\Requests\Admin\Workplace;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['bail', 'nullable', 'string', 'max:255'],
            'id' => ['bail', 'nullable', 'integer'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
