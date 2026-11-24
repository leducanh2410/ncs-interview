<?php

namespace App\Http\Requests\User\Salary;

use App\Rules\MultipleOfThousand;
use Illuminate\Foundation\Http\FormRequest;

class CreateSalaryRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['bail', 'required', 'numeric', 'min:1000', new MultipleOfThousand()],
        ];
    }
}
