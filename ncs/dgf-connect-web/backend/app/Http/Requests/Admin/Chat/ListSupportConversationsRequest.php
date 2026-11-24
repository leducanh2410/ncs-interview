<?php

namespace App\Http\Requests\Admin\Chat;

use App\Enums\SupportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSupportConversationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['bail', 'sometimes', 'string', Rule::in(['unassigned', 'assigned', 'all'])],
            // Open/closed filter, separate from the assignment `status` above. Absent => no filter.
            'conversation_status' => ['bail', 'nullable', 'string', Rule::in(['open', 'closed', 'all'])],
            'page_size' => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['bail', 'nullable', 'string', 'max:255'],
            'support_type' => ['bail', 'nullable', 'string', Rule::enum(SupportType::class)],
        ];
    }
}
