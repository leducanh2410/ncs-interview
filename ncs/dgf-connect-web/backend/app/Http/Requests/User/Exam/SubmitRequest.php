<?php

namespace App\Http\Requests\User\Exam;

use Illuminate\Foundation\Http\FormRequest;

class SubmitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'answers' => ['present', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_id' => ['required', 'integer'],
            'is_final' => ['nullable', 'boolean'],
        ];
    }
}
