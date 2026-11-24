<?php

namespace App\Http\Requests\Admin\DocumentReadConfig;

use Illuminate\Foundation\Http\FormRequest;

class AllConfirmationsIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'config_id' => ['nullable', 'integer', 'exists:document_required_configs,id'],
            'workplace_id' => ['nullable'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['nullable', 'string'],
            'staff_no' => ['nullable', 'string'],
            'page_size' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('config_id') === '') {
            $this->merge(['config_id' => null]);
        }
        if ($this->input('user_id') === '') {
            $this->merge(['user_id' => null]);
        }
    }
}
