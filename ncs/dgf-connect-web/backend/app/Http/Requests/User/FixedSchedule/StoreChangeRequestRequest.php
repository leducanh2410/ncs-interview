<?php

namespace App\Http\Requests\User\FixedSchedule;

use Illuminate\Foundation\Http\FormRequest;

class StoreChangeRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fixed_schedule_id' => ['bail', 'required', 'integer', 'exists:fixed_schedules,id'],
            'days' => ['bail', 'required', 'array', 'min:1'],
            'days.*' => ['bail', 'required', 'array'],
            'days.*.day' => ['bail', 'required', 'integer', 'min:0', 'max:6', 'distinct'],
            'days.*.requested_workplace_shift_id' => ['bail', 'nullable', 'integer'],
        ];
    }
}
