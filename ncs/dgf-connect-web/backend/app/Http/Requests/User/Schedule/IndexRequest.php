<?php

namespace App\Http\Requests\User\Schedule;

use App\Enums\ShiftUserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ShiftUserStatus::values())],
            'dates' => ['required', 'array', 'size:2'],
            'dates.*' => ['required', 'string', 'date_format:Y-m-d'],
        ];
    }
}
