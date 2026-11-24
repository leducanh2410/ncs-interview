<?php

namespace App\Http\Requests\Admin\Manage\CheckIn;

use Illuminate\Foundation\Http\FormRequest;

class GetCheckInShiftRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'staff_no' => ['required', 'string', 'exists:users,staff_no'],
        ];
    }
}
