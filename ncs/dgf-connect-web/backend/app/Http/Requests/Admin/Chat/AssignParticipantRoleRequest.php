<?php

namespace App\Http\Requests\Admin\Chat;

use App\Enums\ChatParticipantRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignParticipantRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role' => [
                'required',
                Rule::in([ChatParticipantRole::Deputy->value, ChatParticipantRole::Employee->value]),
            ],
        ];
    }
}
