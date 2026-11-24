<?php

namespace App\Http\Requests\Admin\User;

use App\Enums\UserPaperItemFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MovePapersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:user_paper_items,id'],
            'folder' => [
                'required',
                'string',
                Rule::in([UserPaperItemFolder::Current->value, UserPaperItemFolder::Old->value]),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'ids' => 'paper item ids',
            'ids.*' => 'paper item id',
            'folder' => 'folder',
        ];
    }
}
