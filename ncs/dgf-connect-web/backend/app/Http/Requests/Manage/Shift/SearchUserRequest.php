<?php

namespace App\Http\Requests\Manage\Shift;

use Illuminate\Foundation\Http\FormRequest;

class SearchUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'min:3'],
            'staff_no' => ['nullable', 'string'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer'],
            'workplace_id' => ['required', 'integer'],
            'workplace_shift_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $q = $this->input('q');
            $groupIds = $this->input('group_ids');
            $hasQ = is_string($q) && mb_trim($q) !== '';
            $hasGroups = is_array($groupIds) && count($groupIds) > 0;
            if (!$hasQ && !$hasGroups) {
                $validator->errors()->add('q', 'Please enter a search keyword or select at least one group.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $groupIds = $this->input('group_ids');
        if (is_string($groupIds)) {
            $this->merge(['group_ids' => [$groupIds]]);
        }
    }
}
