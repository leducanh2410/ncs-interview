<?php

namespace App\Http\Requests\Admin\AdminLeaveRequest;

use App\Enums\AdminLeaveRequestStatus;
use App\Enums\AdminLeaveType;
use App\ModelFilters\AbstractFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'dateFormat:Y-m-d'],
            'date_to' => ['nullable', 'dateFormat:Y-m-d'],
            'requester_id' => ['nullable', 'integer'],
            // "all" is a first-class value here, matching how the other admin list screens send
            // an unfiltered selection.
            'status' => ['nullable', Rule::in([...AdminLeaveRequestStatus::values(), AbstractFilter::ALL_VALUE])],
            'type' => ['nullable', Rule::in([...AdminLeaveType::values(), AbstractFilter::ALL_VALUE])],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
