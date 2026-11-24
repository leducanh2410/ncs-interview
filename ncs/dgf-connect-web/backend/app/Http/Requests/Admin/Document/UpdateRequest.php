<?php

namespace App\Http\Requests\Admin\Document;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['bail', 'nullable', 'string', 'max:255'],
            'users' => ['bail', 'nullable', 'array'],
            'users.*' => ['bail', 'integer', 'exists:users,id'],
            'workplaces' => ['bail', 'nullable', 'array'],
            'workplaces.*' => ['bail', 'integer', 'exists:workplaces,id'],
            'content' => ['bail', 'nullable', 'string'],
            'attachments' => ['bail', 'nullable', 'array'],
            'attachments.*' => ['bail', 'file'],
            'links' => ['bail', 'nullable', 'array'],
            'links.*' => ['bail', 'url'],
            'remove_attachments' => ['bail', 'nullable', 'array'],
            'remove_attachments.*' => ['bail', 'integer'],
            'remove_users' => ['bail', 'nullable', 'array'],
            'remove_users.*' => ['bail', 'integer'],
            'remove_workplaces' => ['bail', 'nullable', 'array'],
            'remove_workplaces.*' => ['bail', 'integer'],
        ];
    }
}
