<?php

namespace App\Http\Requests\Manage\Shift;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fixed_total' => ['required', 'integer', 'min:0'],
            'temp_total' => ['required', 'integer', 'min:0'],
            'max_registered_temp' => ['nullable', 'integer', 'min:0'],
            'break_minutes' => ['nullable', 'integer', 'min:0'],
            'fixed' => [
                'pending_ids' => ['required', 'array'],
                'rejected_ids' => ['required', 'array'],
                'approved_ids' => ['required', 'array'],
                'approved_ids.*' => [
                    'user_id' => ['required', 'integer'],
                    'workplace_position_id' => ['required', 'integer'],
                ],
            ],
            'temp' => [
                'pending_ids' => ['required', 'array'],
                'rejected_ids' => ['required', 'array'],
                'approved_ids' => ['required', 'array'],
                'approved_ids.*' => [
                    'user_id' => ['required', 'integer'],
                    'workplace_position_id' => ['required', 'integer'],
                ],
            ],
        ];
    }
}
