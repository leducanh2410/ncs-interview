<?php

namespace App\Http\Requests\Admin\StressCheck;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;

class CreateStressCheckRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'digits:4', 'min:'.now()->year],
            'start_date' => ['required', 'date_format:Y/m/d'],
            'end_date' => ['required', 'date_format:Y/m/d', 'after_or_equal:start_date'],
        ];

        if ($this->user()->hasRole(RoleName::SuperAdmin->value)) {
            $rules['company_id'] = ['required', 'integer', 'exists:companies,id'];
        } else {
            $rules['start_date'][] = 'after_or_equal:today';
        }

        return $rules;
    }
}
