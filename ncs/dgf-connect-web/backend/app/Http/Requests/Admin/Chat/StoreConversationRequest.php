<?php

namespace App\Http\Requests\Admin\Chat;

use App\Enums\ChatConversationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreConversationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => [
                'bail',
                'required',
                'string',
                Rule::in([
                    ChatConversationType::AdminGroup->value,
                    ChatConversationType::ShiftGroup->value,
                    ChatConversationType::UserGroup->value,
                ]),
            ],
            'title' => ['bail', 'nullable', 'string', 'max:255'],
            'participant_user_ids' => [
                'bail',
                'required_if:type,' . ChatConversationType::AdminGroup->value,
                'prohibited_unless:type,' . ChatConversationType::AdminGroup->value,
                'array',
                'min:1',
            ],
            'participant_user_ids.*' => ['bail', 'integer', 'distinct', 'exists:users,id'],
            'shift_id' => [
                'bail',
                'required_if:type,' . ChatConversationType::ShiftGroup->value,
                'prohibited_unless:type,' . ChatConversationType::ShiftGroup->value,
                'integer',
                'exists:shifts,id',
            ],
            'user_group_id' => [
                'bail',
                'required_if:type,' . ChatConversationType::UserGroup->value,
                'prohibited_unless:type,' . ChatConversationType::UserGroup->value,
                'integer',
                'exists:groups,id',
            ],
            'employee_can_send_message' => ['bail', 'sometimes', 'boolean'],
            'employee_can_send_image' => ['bail', 'sometimes', 'boolean'],
            'employee_can_send_file' => ['bail', 'sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $participantUserIds = $this->input('participant_user_ids', []);
            $creatorId = $this->user()?->id;

            if (
                $creatorId !== null &&
                collect($participantUserIds)->map(fn($userId) => (int) $userId)->contains($creatorId)
            ) {
                $validator->errors()->add('participant_user_ids', 'The creator cannot be an employee participant.');
            }
        });
    }
}
