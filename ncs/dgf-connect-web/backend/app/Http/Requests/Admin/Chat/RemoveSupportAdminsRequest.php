<?php

namespace App\Http\Requests\Admin\Chat;

use Illuminate\Foundation\Http\FormRequest;

class RemoveSupportAdminsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'admin_user_ids' => ['bail', 'required', 'array', 'min:1'],
            'admin_user_ids.*' => ['bail', 'integer', 'distinct', 'exists:users,id'],
        ];
    }
}
