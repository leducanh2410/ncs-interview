<?php

namespace App\Http\Requests\Manage\Announcement;

use App\Enums\Locale;
use App\Rules\NonEmptyHtml;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public const MAX_IMAGE_KILOBYTES = 8192;

    public const MAX_CONTENT_LENGTH = 65535;

    public function rules(): array
    {
        $rules = [
            'title' => ['bail', 'required', 'string', 'max:255'],
            'content' => ['bail', 'required', 'string', 'max:' . self::MAX_CONTENT_LENGTH, new NonEmptyHtml()],
            'workplace_ids' => ['bail', 'required', 'array', 'min:1'],
            'workplace_ids.*' => ['bail', 'integer', 'distinct', 'exists:workplaces,id'],
            'start_at' => ['bail', 'required', 'date_format:Y-m-d H:i:s'],
            'end_at' => ['bail', 'required', 'date_format:Y-m-d H:i:s', 'after:start_at'],
            'is_active' => ['bail', 'required', 'boolean'],
            'image' => ['bail', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . self::MAX_IMAGE_KILOBYTES],
        ];

        foreach (Locale::getBaseTranslationLocales() as $locale) {
            $rules["title_{$locale}"] = ['bail', 'nullable', "required_with:content_{$locale}", 'string', 'max:255'];
            $rules["content_{$locale}"] = [
                'bail',
                'nullable',
                "required_with:title_{$locale}",
                'string',
                'max:' . self::MAX_CONTENT_LENGTH,
                new NonEmptyHtml(),
            ];
        }

        return $rules;
    }
}
