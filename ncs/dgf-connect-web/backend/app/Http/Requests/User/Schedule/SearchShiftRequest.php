<?php

namespace App\Http\Requests\User\Schedule;

use Illuminate\Foundation\Http\FormRequest;

class SearchShiftRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['bail', 'required', 'date_format:Y-m-d'],
            'workplace_id' => ['bail', 'integer'],
        ];
    }
}
