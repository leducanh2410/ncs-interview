<?php

namespace App\Http\Requests\Manage\Reconciliation;

use App\Enums\ReconciliationDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDecideReconciliationBatchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'side' => ['bail', 'required', 'string', Rule::in(ReconciliationDecision::values())],
        ];
    }
}
