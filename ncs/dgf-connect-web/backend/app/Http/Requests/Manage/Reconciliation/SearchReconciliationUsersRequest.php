<?php

namespace App\Http\Requests\Manage\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;

class SearchReconciliationUsersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['bail', 'required_without:key', 'string', 'min:1'],
            'key' => ['bail', 'required_without:q', 'integer'],
            'work_date' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'page_size' => ['bail', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
