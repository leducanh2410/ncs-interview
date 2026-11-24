<?php

namespace App\Http\Requests\Manage\Salary;

use Illuminate\Foundation\Http\FormRequest;

class GetUserSalaryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
        ];
    }
}
