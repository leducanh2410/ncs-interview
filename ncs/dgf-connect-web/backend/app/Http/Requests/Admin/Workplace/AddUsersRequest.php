<?php

namespace App\Http\Requests\Admin\Workplace;

use Illuminate\Foundation\Http\FormRequest;

class AddUsersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_ids' => ['bail', ' required', 'array', 'min:1'],
            'user_ids.*' => ['bail', ' required', 'integer', 'exists:users,id'],
        ];
    }
}
