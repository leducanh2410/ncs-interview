<?php

namespace App\Http\Requests\Admin\PostalCode;

use Illuminate\Foundation\Http\FormRequest;

class ResolvePostalCodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'address' => ['required', 'string', 'max:255'],
            'prefecture' => ['nullable', 'string', 'max:50'],
        ];
    }
}
