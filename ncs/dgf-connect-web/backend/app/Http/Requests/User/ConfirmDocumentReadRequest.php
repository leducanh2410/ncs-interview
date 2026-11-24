<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmDocumentReadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'device_info' => ['bail', 'nullable', 'array'],
            'device_info.model' => ['bail', 'nullable', 'string', 'max:255'],
            'device_info.os' => ['bail', 'nullable', 'string', 'max:255'],
            'device_info.app_version' => ['bail', 'nullable', 'string', 'max:255'],
        ];
    }
}
