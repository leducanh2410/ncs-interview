<?php

namespace App\Http\Requests\User\CheckIn;

use Illuminate\Foundation\Http\FormRequest;

class GetHistoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
        ];
    }
}
