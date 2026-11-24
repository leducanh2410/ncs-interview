<?php

namespace App\Http\Requests\Manage\WorkHistoryEditRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkResolveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['bail', 'required', 'array', 'min:1'],
            'ids.*' => ['bail', 'required', 'integer', 'exists:work_history_edit_requests,id'],
            'action' => ['bail', 'required', 'string', Rule::in(['approve', 'reject'])],
            'reject_reason' => ['bail', 'required_if:action,reject', 'nullable', 'string', 'max:1000'],
        ];
    }
}
