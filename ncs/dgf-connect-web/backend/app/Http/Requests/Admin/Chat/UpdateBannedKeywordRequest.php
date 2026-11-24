<?php

namespace App\Http\Requests\Admin\Chat;

use App\Enums\ChatBannedKeywordAction;
use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBannedKeywordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['sometimes', 'filled', 'string', 'max:255'],
            'locale' => ['sometimes', 'nullable', Rule::enum(Locale::class)],
            'action' => ['sometimes', Rule::enum(ChatBannedKeywordAction::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
