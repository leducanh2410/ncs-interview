<?php

namespace App\Http\Requests\Manage\Reconciliation;

use App\Services\Reconciliation\ReconciliationParserRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReconciliationBatchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source' => ['bail', 'required', 'string', Rule::in(app(ReconciliationParserRegistry::class)->sources())],
            'file' => ['bail', 'required', 'file', 'mimes:xlsx', 'max:10240'],
        ];
    }
}
