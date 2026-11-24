<?php

namespace App\Http\Requests\Admin\CompanyHoliday;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Ignore the row being edited so renaming a holiday without moving it is not
            // rejected as a duplicate of itself.
            'date' => [
                'required',
                'dateFormat:Y-m-d',
                Rule::unique('company_holidays', 'date')->ignore($this->route('companyHoliday')),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
