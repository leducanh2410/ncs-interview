<?php

namespace App\Http\Requests\Admin\User;

use App\Enums\UserPaperType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExtractPapersOcrRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'types' => ['required', 'array', 'min:1'],
            'types.*' => [
                'required',
                'string',
                'distinct',
                Rule::in([
                    UserPaperType::ForeignerCardFront->value,
                    UserPaperType::ForeignerCardBack->value,
                    UserPaperType::ForeignerCardScan->value,
                    UserPaperType::Passport->value,
                    UserPaperType::StudentCardFront->value,
                    UserPaperType::StudentCardBack->value,
                ]),
            ],
            'force' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'types' => 'paper types',
            'types.*' => 'paper type',
        ];
    }
}
