<?php

namespace App\Http\Requests\Admin\Document;

use Illuminate\Foundation\Http\FormRequest;

class CreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['bail', 'required', 'string', 'max:255'],
            'users' => ['bail', 'nullable', 'array'],
            'users.*' => ['bail', 'integer', 'exists:users,id'],
            'workplaces' => ['bail', 'nullable', 'array'],
            'workplaces.*' => ['bail', 'integer', 'exists:workplaces,id'],
            'content' => ['bail', 'required', 'string'],
            'attachments' => ['bail', 'nullable', 'array'],
            'attachments.*' => ['bail', 'file'],
            'links' => ['bail', 'nullable', 'array'],
            'links.*' => ['bail', 'url'],
        ];
    }
}
