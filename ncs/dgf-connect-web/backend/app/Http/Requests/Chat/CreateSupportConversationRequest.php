<?php

namespace App\Http\Requests\Chat;

use App\Enums\SupportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSupportConversationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['bail', 'required', 'string', 'max:5000'],
            'title' => ['bail', 'nullable', 'string', 'max:255'],
            'client_message_id' => ['bail', 'nullable', 'string', 'uuid', 'max:64'],
            'support_type' => ['bail', 'nullable', 'string', Rule::enum(SupportType::class)],
        ];
    }
}
