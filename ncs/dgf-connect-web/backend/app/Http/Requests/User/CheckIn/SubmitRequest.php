<?php

namespace App\Http\Requests\User\CheckIn;

use Illuminate\Foundation\Http\FormRequest;

class SubmitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'shift_user_id' => ['required', 'integer'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'image' => ['required', 'image', 'mimes:jpeg,jpg', 'max:500'],
        ];
    }
}
