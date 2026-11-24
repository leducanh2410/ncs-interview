<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'conversation_id' => ['bail', 'required', 'integer', 'exists:chat_conversations,id'],
        ];
    }
}
