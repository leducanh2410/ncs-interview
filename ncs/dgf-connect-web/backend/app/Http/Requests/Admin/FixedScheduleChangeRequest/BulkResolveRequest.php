<?php

namespace App\Http\Requests\Admin\FixedScheduleChangeRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkResolveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['bail', 'required', 'array', 'min:1'],
            'ids.*' => ['bail', 'required', 'integer', 'exists:fixed_schedule_change_requests,id'],
            'action' => ['bail', 'required', 'string', Rule::in(['approve', 'reject'])],
        ];
    }
}
