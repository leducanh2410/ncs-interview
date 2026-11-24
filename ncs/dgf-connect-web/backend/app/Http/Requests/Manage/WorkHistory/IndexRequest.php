<?php

namespace App\Http\Requests\Manage\WorkHistory;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'dates' => ['nullable', 'array'],
            'workplace_id' => ['nullable', 'numeric'],
            'user_id' => ['nullable', 'numeric'],
            'page_size' => ['nullable', 'integer'],
            'attendance_status' => ['nullable', Rule::enum(AttendanceStatus::class)],
        ];
    }
}
