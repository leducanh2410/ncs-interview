<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'company_code' => ['bail', 'nullable', 'max:255'],
            'login_id' => ['bail', 'required', 'max:255'],
            'password' => ['bail', 'required', 'max:255'],
            'remember' => ['bail', 'nullable', 'boolean'],
            'login_type' => ['bail', 'required', 'in:staff,admin,super_admin'],
        ];
    }
}
