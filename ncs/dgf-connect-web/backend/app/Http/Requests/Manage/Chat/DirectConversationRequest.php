<?php

namespace App\Http\Requests\Manage\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DirectConversationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => [
                'bail',
                'required',
                'integer',
                'exists:users,id',
                // Self-lookup is invalid input (there is no 1-on-1 with yourself). Returning 422
                // rather than 404 lets the client distinguish this from "no conversation yet"
                // and skip the create-after-404 fallback, which would itself 422.
                Rule::notIn([(int) $this->user()?->id]),
            ],
        ];
    }
}
