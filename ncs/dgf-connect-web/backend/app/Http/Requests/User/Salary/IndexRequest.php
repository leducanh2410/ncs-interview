<?php

namespace App\Http\Requests\User\Salary;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'year' => ['nullable', 'date_format:Y'],
        ];
    }
}
