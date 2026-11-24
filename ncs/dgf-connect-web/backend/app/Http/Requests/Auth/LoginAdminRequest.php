<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginAdminRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'email', 'max:255'],
            'password' => ['bail', 'required', 'max:255'],
            'remember' => ['bail', 'nullable', 'boolean'],
            'device_id' => ['bail', 'nullable', 'string'],
            'device_name' => ['bail', 'nullable', 'string'],
            'platform' => ['bail', 'nullable', 'string'],
            'fcm_token' => ['bail', 'nullable', 'string'],
        ];
    }
}
