<?php

namespace App\Http\Requests\Admin\CompanyHoliday;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'dateFormat:Y-m-d', Rule::unique('company_holidays', 'date')],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
