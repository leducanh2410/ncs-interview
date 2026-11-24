<?php

namespace App\Http\Requests\Admin\DocumentReadConfig;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'document_id' => ['bail', 'required', 'integer', 'exists:documents,id'],
            'workplace_id' => ['bail', 'nullable', 'integer', 'exists:workplaces,id'],
            'title_vi' => ['bail', 'required', 'string', 'max:255'],
            'title_en' => ['bail', 'required', 'string', 'max:255'],
            'title_ja' => ['bail', 'required', 'string', 'max:255'],
            'title_ne' => ['bail', 'nullable', 'string', 'max:255'],
            'title_si' => ['bail', 'nullable', 'string', 'max:255'],
            'is_active' => ['bail', 'boolean'],
        ];
    }
}
