<?php

namespace App\Http\Requests\Staff\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:4', 'confirmed'],
            'birthday' => ['required', 'date_format:Ymd'],
        ];
    }
}
