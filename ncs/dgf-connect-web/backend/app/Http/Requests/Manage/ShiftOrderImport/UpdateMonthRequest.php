<?php

namespace App\Http\Requests\Manage\ShiftOrderImport;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMonthRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'month' => ['required', 'string', 'date_format:Y-m'],
        ];
    }
}
