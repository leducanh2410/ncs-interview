<?php

namespace App\Http\Requests\Manage\Salary;

use Illuminate\Foundation\Http\FormRequest;

class RejectSalaryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
