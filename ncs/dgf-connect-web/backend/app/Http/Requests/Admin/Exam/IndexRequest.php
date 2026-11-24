<?php

namespace App\Http\Requests\Admin\Exam;

use App\Enums\ExamType;
use App\ModelFilters\AbstractFilter;
use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['bail', 'nullable', 'string', 'max:255'],
            'is_required' => ['nullable', 'string'],
            'workplace_id' => ['nullable', 'integer'],
            'end_at' => ['nullable', 'date'],
            'status' => [
                'nullable',
                Rule::in([AbstractFilter::ALL_VALUE, Exam::STATUS_SCHEDULED, Exam::STATUS_ACTIVE, Exam::STATUS_ENDED]),
            ],
            'type' => ['nullable', Rule::in([AbstractFilter::ALL_VALUE, ...ExamType::values()])],
            'id' => ['bail', 'nullable', 'integer'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
