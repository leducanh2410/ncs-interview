<?php

namespace App\Http\Requests\Admin\AdminLeaveRequest;

use Illuminate\Foundation\Http\FormRequest;

class RejectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // A refusal without a stated reason is not a decision the requester can act on, so
            // the reason is required rather than optional.
            'reject_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
