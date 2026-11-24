<?php

namespace App\Http\Requests\Manage\Reconciliation;

use App\Enums\ReconciliationSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexReconciliationBatchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source' => ['bail', 'nullable', 'string', Rule::in(ReconciliationSource::values())],
            'uploaded_by' => ['bail', 'nullable', 'integer'],
            'dates' => ['bail', 'nullable', 'array', 'size:2'],
            'dates.*' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'page_size' => ['bail', 'nullable', 'integer'],
        ];
    }
}
