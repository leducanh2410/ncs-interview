<?php

namespace App\Http\Requests\Admin\StressCheck;

use Illuminate\Foundation\Http\FormRequest;

class GetExamineesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:40'],
            'sort' => ['nullable', 'string'],
        ];
    }
}
