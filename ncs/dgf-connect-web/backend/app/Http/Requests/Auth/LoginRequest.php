<?php

namespace App\Http\Requests\Auth;

use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'device_id' => ['bail', 'required', 'string'],
            'device_name' => ['bail', 'required', 'string'],
            'platform' => ['bail', 'required', 'string'],
            'fcm_token' => ['bail', 'nullable', 'string'],
            'lang' => ['bail', 'nullable', 'string', Rule::in(Locale::values())],
            'email' => ['bail', 'required', 'email', 'max:255'],
            'password' => ['bail', 'required', 'max:255'],
            'remember' => ['bail', 'nullable', 'boolean'],
        ];
    }
}
