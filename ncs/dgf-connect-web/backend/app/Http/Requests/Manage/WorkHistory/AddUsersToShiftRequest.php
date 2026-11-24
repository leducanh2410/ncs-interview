<?php

namespace App\Http\Requests\Manage\WorkHistory;

use Illuminate\Foundation\Http\FormRequest;

class AddUsersToShiftRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer'],
            'user_ids' => ['required', 'array'],
        ];
    }
}
