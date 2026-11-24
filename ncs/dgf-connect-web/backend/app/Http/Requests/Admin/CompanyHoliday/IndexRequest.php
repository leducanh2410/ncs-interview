<?php

namespace App\Http\Requests\Admin\CompanyHoliday;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:2000,2100'],
        ];
    }
}
