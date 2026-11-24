<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class MarkReadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'message_id' => ['bail', 'nullable', 'integer', 'min:1'],
        ];
    }
}
