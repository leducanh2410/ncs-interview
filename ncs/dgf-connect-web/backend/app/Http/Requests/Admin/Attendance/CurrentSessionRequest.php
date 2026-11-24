<?php

namespace App\Http\Requests\Admin\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class CurrentSessionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['required', 'integer', 'exists:workplaces,id'],
        ];
    }
}
