<?php

namespace App\Http\Requests\SuperAdmin\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyNoteRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
