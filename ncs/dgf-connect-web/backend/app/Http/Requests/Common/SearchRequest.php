<?php

namespace App\Http\Requests\Common;

use App\Rules\StringOrArray;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => [
                'string',
                'min:3',
                Rule::requiredIf(function () {
                    return empty($this->input('key'));
                }),
            ],
            'key' => [
                new StringOrArray(),
                Rule::requiredIf(function () {
                    return empty($this->input('q'));
                }),
            ],
        ];
    }
}
