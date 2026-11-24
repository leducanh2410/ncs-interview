<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class ListMessagesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'before_id' => ['bail', 'nullable', 'integer', 'min:1'],
            'after_id' => ['bail', 'nullable', 'integer', 'min:1'],
            'limit' => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
