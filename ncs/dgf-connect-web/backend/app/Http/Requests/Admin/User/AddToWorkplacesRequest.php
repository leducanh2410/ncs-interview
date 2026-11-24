<?php

namespace App\Http\Requests\Admin\User;

use Illuminate\Foundation\Http\FormRequest;

class AddToWorkplacesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_ids' => ['required', 'array'],
        ];
    }
}
