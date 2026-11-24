<?php

namespace App\Http\Requests\Manage\Salary;

use App\Enums\SalaryRequestType;
use App\Rules\MultipleOfThousand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSalaryRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'amount' => [
                'required',
                'numeric',
                'min:0',
                Rule::when(request()->input('type') === SalaryRequestType::Transfer->value, new MultipleOfThousand()),
            ],
            'type' => ['required', 'string', Rule::in(SalaryRequestType::values())],
        ];
    }
}
