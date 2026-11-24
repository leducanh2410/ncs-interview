<?php

namespace App\Http\Requests\Admin\DocumentReadConfig;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmationIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'page_size' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('user_id') === '') {
            $this->merge(['user_id' => null]);
        }
    }
}
