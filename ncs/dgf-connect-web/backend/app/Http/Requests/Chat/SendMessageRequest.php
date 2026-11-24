<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['bail', 'nullable', 'string', 'max:5000', 'required_without:files'],
            'client_message_id' => ['bail', 'nullable', 'string', 'uuid', 'max:64'],
            'files' => ['nullable', 'array', 'max:' . config('chat.attachments.max_files')],
            'files.*' => [
                'file',
                'mimes:' . config('chat.attachments.mimes'),
                'max:' . config('chat.attachments.max_size_kb'),
            ],
        ];
    }
}
