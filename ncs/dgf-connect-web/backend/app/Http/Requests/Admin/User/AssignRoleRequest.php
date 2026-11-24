<?php

namespace App\Http\Requests\Admin\User;

use Illuminate\Foundation\Http\FormRequest;

class AssignRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role_name' => ['required', 'string'],
            'workplace_id' => ['nullable', 'integer', 'exists:workplaces,id'],
        ];
    }
}
