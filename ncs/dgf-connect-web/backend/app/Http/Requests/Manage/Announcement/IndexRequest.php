<?php

namespace App\Http\Requests\Manage\Announcement;

use App\Enums\AnnouncementState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'workplace_id' => ['bail', 'nullable', 'integer', 'exists:workplaces,id'],
            'state' => ['bail', 'nullable', 'string', Rule::in(AnnouncementState::values())],
            'keyword' => ['bail', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'workplace_id' => $this->input('workplace_id') === 'all' ? null : $this->input('workplace_id'),
            'state' => $this->input('state') === 'all' ? null : $this->input('state'),
        ]);
    }
}
