<?php

namespace App\Http\Requests\Manage\ShiftPlanning;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['required', 'integer'],
            'from' => ['nullable', 'dateFormat:Y-m-d'],
            'member_activity_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
