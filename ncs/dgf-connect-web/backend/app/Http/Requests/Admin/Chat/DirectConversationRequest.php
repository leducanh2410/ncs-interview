<?php

namespace App\Http\Requests\Admin\Chat;

use Illuminate\Foundation\Http\FormRequest;

class DirectConversationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['bail', 'required', 'integer', 'exists:users,id'],
        ];
    }
}
