<?php

namespace App\Http\Requests\User\Exam;

use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ExamLanguageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'lang' => ['nullable', new Enum(Locale::class)],
        ];
    }
}
