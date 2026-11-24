<?php

namespace App\Http\Requests\User\CheckIn;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitAttendanceReasonRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([AttendanceStatus::Late->value, AttendanceStatus::EarlyLeave->value])],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
