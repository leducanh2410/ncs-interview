<?php

namespace App\Http\Requests\Admin\Translation;

use App\Enums\Locale;
use App\Services\External\OpenRouterTranslationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TranslateContentRequest extends FormRequest
{
    // One LLM call must comfortably finish inside the provider timeout, so cap the batch size.
    private const MAX_TOTAL_CHARS = 20000;

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.key' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[a-z0-9_]+$/'],
            'items.*.text' => ['required', 'string', 'max:5000'],
            'source_locale' => ['required', 'string', Rule::in(Locale::values())],
            'target_locales' => ['required', 'array', 'min:1'],
            'target_locales.*' => ['required', 'string', 'distinct', Rule::in(Locale::values())],
            'context' => ['required', 'string', Rule::in(OpenRouterTranslationService::contexts())],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $sourceLocale = $this->input('source_locale');
                $targetLocales = $this->input('target_locales', []);
                if (is_array($targetLocales) && in_array($sourceLocale, $targetLocales, true)) {
                    $validator->errors()->add('target_locales', 'The source locale cannot be a target locale.');
                }

                $items = $this->input('items', []);
                if (!is_array($items)) {
                    return;
                }
                $totalChars = 0;
                foreach ($items as $item) {
                    $text = is_array($item) ? $item['text'] ?? '' : '';
                    $totalChars += is_string($text) ? mb_strlen($text) : 0;
                }
                if ($totalChars > self::MAX_TOTAL_CHARS) {
                    $validator->errors()->add('items', 'The combined text is too long to translate in one request.');
                }
            },
        ];
    }
}
