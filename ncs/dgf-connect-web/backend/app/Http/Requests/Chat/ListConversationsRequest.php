<?php

namespace App\Http\Requests\Chat;

use App\Enums\ChatConversationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListConversationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'page_size' => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['bail', 'nullable', 'string', 'max:255'],
            // Absent => defaults to open (see GetConversationsAction). 'all' shows both statuses.
            'status' => ['bail', 'nullable', Rule::in([...ChatConversationStatus::values(), 'all'])],
            // Optional split so clients can page group chats and support conversations independently.
            'category' => ['bail', 'nullable', Rule::in(['chat', 'support'])],
        ];
    }
}
