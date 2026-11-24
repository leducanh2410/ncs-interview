<?php

namespace App\Http\Requests\Admin\Chat;

use App\Enums\ChatBannedKeywordAction;
use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBannedKeywordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', Rule::enum(Locale::class)],
            'action' => ['required', Rule::enum(ChatBannedKeywordAction::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
