<?php

namespace App\Http\Requests\Admin\User;

use Illuminate\Foundation\Http\FormRequest;

class RestorePapersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:user_paper_items,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'ids' => 'paper item ids',
            'ids.*' => 'paper item id',
        ];
    }
}
