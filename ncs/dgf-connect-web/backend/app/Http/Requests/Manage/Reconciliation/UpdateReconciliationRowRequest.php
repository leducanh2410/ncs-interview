<?php

namespace App\Http\Requests\Manage\Reconciliation;

use App\Enums\ReconciliationDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReconciliationRowRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'skipped' => ['sometimes', 'boolean'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'check_in_id' => ['sometimes', 'nullable', 'integer', 'exists:check_ins,id'],
            'decisions' => ['sometimes', 'array'],
            'decisions.check_in_time' => ['nullable', Rule::in(ReconciliationDecision::values())],
            'decisions.check_out_time' => ['nullable', Rule::in(ReconciliationDecision::values())],
            'decisions.break_minutes' => ['nullable', Rule::in(ReconciliationDecision::values())],
        ];
    }
}
