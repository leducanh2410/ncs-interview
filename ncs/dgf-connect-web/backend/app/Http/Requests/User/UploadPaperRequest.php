<?php

namespace App\Http\Requests\User;

use App\Enums\UserPaperType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadPaperRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['bail', 'required', 'string', Rule::in(UserPaperType::values())],
            'files' => ['required', 'array'],
            'files.*' => ['required', 'image', 'mimes:jpg,jpeg,png,bmp,webp,heic', 'max:8192'],
        ];
    }
}
