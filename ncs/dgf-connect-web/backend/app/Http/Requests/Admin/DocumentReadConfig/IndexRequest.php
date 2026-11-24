<?php

namespace App\Http\Requests\Admin\DocumentReadConfig;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['nullable', 'integer', 'exists:workplaces,id'],
            'scope' => ['nullable', 'string', 'in:system,workplace'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
