<?php

namespace App\Http\Requests\Admin\ImportHistory;

use App\Enums\ImportBatchType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(array_column(ImportBatchType::cases(), 'value'))],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
