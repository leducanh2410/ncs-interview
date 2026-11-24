<?php

namespace App\Http\Requests\Admin\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApprovePapersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:user_paper_items,id'],
            'items.*.approved' => ['required', 'boolean'],
            'rejected_note' => [
                'nullable',
                'string',
                Rule::requiredIf(function () {
                    return collect($this->input('items', []))->contains(
                        fn($item) => isset($item['approved']) && !$item['approved']
                    );
                }),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'items' => 'items',
            'items.*.id' => 'item id',
            'items.*.approved' => 'approved status',
            'rejected_note' => 'reject note',
        ];
    }
}
