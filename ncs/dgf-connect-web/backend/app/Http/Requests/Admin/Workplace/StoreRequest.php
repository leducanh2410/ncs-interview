<?php

namespace App\Http\Requests\Admin\Workplace;

use App\Rules\Coordinate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:255',
                Rule::unique('workplaces', 'name')->ignore($this->route('workplace')),
            ],
            'address' => ['bail', 'string', 'required', 'max:255'],
            'coordinates' => ['bail', 'string', 'required', 'max:255', new Coordinate()],
            'salary_per_hour' => ['bail', 'nullable', 'numeric', 'min:0'],
            'min_rest_hours' => ['bail', 'nullable', 'integer', 'min:0', 'max:24'],
            'overtime_after_hours' => ['bail', 'nullable', 'integer', 'min:1', 'max:24'],
            'is_test' => ['bail', 'nullable', 'boolean'],
            'is_yamato' => ['bail', 'nullable', 'boolean'],
            'is_self_register_disabled' => ['bail', 'nullable', 'boolean'],
        ];
    }
}
