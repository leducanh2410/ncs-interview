<?php

namespace App\Http\Requests\Admin\Chat;

use Illuminate\Foundation\Http\FormRequest;

class PinMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'message_id' => ['bail', 'required', 'integer', 'exists:chat_messages,id'],
        ];
    }
}
