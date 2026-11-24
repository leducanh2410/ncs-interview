<?php

namespace App\Http\Requests\Manage\WorkHistoryEditRequest;

use App\Enums\WorkHistoryEditRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['bail', 'nullable', 'integer'],
            'employee_name' => ['bail', 'nullable', 'string'],
            'status' => ['bail', 'nullable', 'string', Rule::in([...WorkHistoryEditRequestStatus::values(), 'all'])],
            'dates' => ['bail', 'nullable', 'array', 'size:2'],
            'dates.*' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'page_size' => ['bail', 'nullable', 'integer'],
        ];
    }
}
