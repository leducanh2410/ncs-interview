<?php

namespace App\Http\Requests\SuperAdmin\Company;

use Illuminate\Foundation\Http\FormRequest;

class ShowRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'stress_check_order_by' => ['nullable', 'string', 'in:id,year,status'],
            'stress_check_direction' => ['nullable', 'string', 'in:asc,desc'],
            'user_order_by' => ['nullable', 'string', 'in:name,status'],
            'user_direction' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
