<?php

namespace App\Http\Requests\Admin\FixedScheduleChangeConfig;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_ids' => ['bail', 'required', 'array', 'min:1'],
            'workplace_ids.*' => ['bail', 'required', 'integer', 'exists:workplaces,id'],
            'is_enabled' => ['bail', 'required', 'boolean'],
            'deadline' => ['bail', 'nullable', 'date'],
        ];
    }
}
