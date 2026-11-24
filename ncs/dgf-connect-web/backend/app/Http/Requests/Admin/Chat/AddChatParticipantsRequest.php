<?php

namespace App\Http\Requests\Admin\Chat;

use Illuminate\Foundation\Http\FormRequest;

class AddChatParticipantsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_ids' => ['bail', 'required', 'array', 'min:1'],
            'user_ids.*' => ['bail', 'integer', 'distinct', 'exists:users,id'],
        ];
    }
}
