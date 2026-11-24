<?php

namespace App\Http\Requests\User;

use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'lang' => ['bail', 'required', 'string', Rule::in(Locale::values())],
        ];
    }
}
