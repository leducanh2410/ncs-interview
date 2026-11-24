<?php

namespace App\Http\Requests\Manage\WorkHistoryEditRequest;

use Illuminate\Foundation\Http\FormRequest;

class ApproveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'force' => ['bail', 'nullable', 'boolean'],
        ];
    }
}
