<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginAdminVerifyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'key' => ['bail', 'required', 'max:255'],
            'code' => ['bail', 'required', 'max:255'],
            'device_id' => ['bail', 'nullable', 'string'],
            'device_name' => ['bail', 'nullable', 'string'],
            'platform' => ['bail', 'nullable', 'string'],
            'fcm_token' => ['bail', 'nullable', 'string'],
        ];
    }
}
