<?php

namespace App\Http\Requests\Manage\ShiftPlanning;

use Illuminate\Foundation\Http\FormRequest;

class LockRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['required', 'integer'],
            // Confirmed takeover of a stale/expired lock (requirements §4.1).
            'force' => ['nullable', 'boolean'],
        ];
    }
}
