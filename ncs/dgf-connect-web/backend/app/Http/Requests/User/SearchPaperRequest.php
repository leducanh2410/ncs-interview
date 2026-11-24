<?php

namespace App\Http\Requests\User;

use App\Enums\UserPaperType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchPaperRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['bail', 'string', Rule::in(UserPaperType::values())],
        ];
    }
}
