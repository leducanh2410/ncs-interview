<?php

namespace App\Http\Requests\Admin\Group;

use App\Enums\GroupKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['nullable', 'array'],
            'kind.*' => ['bail', 'string', Rule::in(GroupKind::values())],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $kind = $this->input('kind');
        if (is_string($kind)) {
            $this->merge(['kind' => [$kind]]);
        }
    }
}
