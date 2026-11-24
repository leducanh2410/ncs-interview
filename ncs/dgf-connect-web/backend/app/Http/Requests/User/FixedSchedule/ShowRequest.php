<?php

namespace App\Http\Requests\User\FixedSchedule;

use Illuminate\Foundation\Http\FormRequest;

class ShowRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['bail', 'nullable', 'integer'],
        ];
    }
}
