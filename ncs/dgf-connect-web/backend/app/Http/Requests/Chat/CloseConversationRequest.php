<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class CloseConversationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'close_reason' => ['bail', 'nullable', 'string', 'max:255'],
        ];
    }
}
