<?php

namespace App\Http\Requests\Staff\Exam;

use App\Models\QuestionOption;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AnswerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'answers' => ['nullable', 'array'],
            'answers.*.question_id' => ['required', 'integer', 'exists:questions,id'],
            'answers.*.option_id' => [
                'required',
                'integer',
                'exists:question_options,id',
                function (string $attribute, mixed $value, Closure $fail) {
                    $index = explode('.', $attribute)[1];
                    $questionId = $this->input("answers.{$index}.question_id");

                    if ($questionId && ! QuestionOption::where('id', $value)->where('question_id', $questionId)->exists()) {
                        $fail(__('The selected option does not belong to the specified question.'));
                    }
                },
            ],
            'is_submitted' => ['required', 'boolean'],
        ];
    }
}
