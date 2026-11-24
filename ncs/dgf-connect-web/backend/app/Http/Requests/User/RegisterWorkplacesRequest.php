<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class RegisterWorkplacesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_ids' => ['required', 'array', 'max:5'],
            'workplace_ids.*' => ['required', 'integer', 'exists:workplaces,id'],
        ];
    }
}
