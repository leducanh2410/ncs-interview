<?php

namespace App\Http\Requests\Manage\Schedule;

use Illuminate\Foundation\Http\FormRequest;

class GetUserScheduleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'dateFormat:Y-m-d'],
            'end_date' => ['nullable', 'dateFormat:Y-m-d'],
        ];
    }
}
