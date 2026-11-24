<?php

namespace App\Http\Requests\Admin\Manage\CheckIn;

use Illuminate\Foundation\Http\FormRequest;

class SubmitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'staff_no' => ['required', 'string', 'exists:users,staff_no'],
            'shift_user_id' => ['required', 'integer'],
            'image' => ['required', 'image', 'mimes:jpeg,jpg', 'max:500'],
        ];
    }
}
