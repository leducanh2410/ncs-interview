<?php

namespace App\Services\External;

use App\Enums\Locale;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

readonly class OpenRouterTranslationService
{
    public const CONTEXT_NOTIFICATION = 'notification';

    public const CONTEXT_POLL = 'poll';

    public const CONTEXT_DOCUMENT_READ_CONFIG = 'document_read_config';

    public const CONTEXT_EXAM = 'exam';

    public const CONTEXT_ANNOUNCEMENT = 'announcement';

    private PendingRequest $client;

    private string $model;

    private ?string $fallbackModel;

    public function __construct()
    {
        $config = config('services.openrouter');
        $this->model = $config['translation_model'] ?? $config['model'];
        $this->fallbackModel = $config['fallback_model'] ?? null;
        // No retry: the whole request must finish before the web server's 60s limit.
        $this->client = Http::baseUrl($config['base_url'])
            ->timeout((int) $config['timeout'])
            ->withToken($config['api_key']);
    }

    /**
     * @return string[]
     */
    public static function contexts(): array
    {
        return [
            self::CONTEXT_NOTIFICATION,
            self::CONTEXT_POLL,
            self::CONTEXT_DOCUMENT_READ_CONFIG,
            self::CONTEXT_EXAM,
            self::CONTEXT_ANNOUNCEMENT,
        ];
    }

    /**
     * Translate a batch of texts into every target locale in a single LLM call.
     *
     * @param array<string, string> $items key => source text
     * @param string[] $targetLocales
     * @param string $context one of the CONTEXT_* constants
     * @return array<string, array<string, string>> locale => [key => translated text]
     */
    public function translate(array $items, string $sourceLocale, array $targetLocales, string $context): array
    {
        $payload = [
            'model' => $this->model,
            'temperature' => 0,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->getPrompt($items, $sourceLocale, $targetLocales, $context),
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'translations',
                    'strict' => true,
                    'schema' => $this->getSchema($items, $targetLocales),
                ],
            ],
        ];

        if ($this->fallbackModel !== null) {
            // OpenRouter routes to the next model in the list when the primary provider errors (5xx, 429, ...)
            $payload['models'] = [$this->model, $this->fallbackModel];
        }

        $response = $this->client->post('chat/completions', $payload);

        if (!$response->successful()) {
            $this->logFailure($response, $context);

            throw new RuntimeException('Translation provider request failed');
        }

        $text = Arr::get($response->json(), 'choices.0.message.content');
        $translations = is_string($text) ? json_decode($text, true) : null;

        if (!$this->hasValidTranslations($translations, $items, $targetLocales)) {
            Log::error('OpenRouter translation returned invalid JSON', [
                'context' => $context,
                'content' => $text,
            ]);

            throw new RuntimeException('Translation provider returned invalid JSON');
        }

        return Arr::only($translations, $targetLocales);
    }

    /**
     * @param mixed $translations
     * @param array<string, string> $items
     * @param string[] $targetLocales
     */
    private function hasValidTranslations(mixed $translations, array $items, array $targetLocales): bool
    {
        if (!is_array($translations) || array_diff($targetLocales, array_keys($translations)) !== []) {
            return false;
        }

        $itemKeys = array_keys($items);
        foreach ($targetLocales as $locale) {
            $translatedItems = $translations[$locale];
            if (!is_array($translatedItems) || array_diff($itemKeys, array_keys($translatedItems)) !== []) {
                return false;
            }
            foreach ($itemKeys as $itemKey) {
                if (!is_string($translatedItems[$itemKey]) || mb_trim($translatedItems[$itemKey]) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    private function getPrompt(array $items, string $sourceLocale, array $targetLocales, string $context): string
    {
        $targetNames = implode(', ', array_map(fn(string $locale) => $this->localeName($locale), $targetLocales));

        return implode(' ', [
            'You are a professional translator for a workforce-management app used by foreign workers in Japan.',
            sprintf(
                'Translate every input text from %s into each of these target languages: %s.',
                $this->localeName($sourceLocale),
                $targetNames
            ),
            $this->getContextSentence($context),
            'Keep the tone concise and polite, in the style of an app notification or UI text.',
            'Preserve numbers, dates, URLs, emoji, placeholders such as :title or {{name}}, and line breaks exactly.',
            'Do not add explanations.',
            'Return ONLY the JSON object described by the schema.',
            'Input texts as JSON: ' . json_encode($items, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * The context hint is a server-side canned sentence keyed by an enum-validated value, so
     * client input can never inject instructions into the prompt.
     */
    private function getContextSentence(string $context): string
    {
        return match ($context) {
            self::CONTEXT_NOTIFICATION => 'The texts are an announcement notification (title and body).',
            self::CONTEXT_POLL => 'The texts are a poll/survey title, description and answer choices.',
            self::CONTEXT_DOCUMENT_READ_CONFIG => 'The texts are titles of documents employees are required to read.',
            self::CONTEXT_EXAM => 'The texts are exam titles, questions and answer options.',
            self::CONTEXT_ANNOUNCEMENT
                => 'The texts are a workplace announcement title and its body. The body is HTML: translate only the human-readable text and return every tag and attribute unchanged.',
            default => throw new RuntimeException('Unknown translation context: ' . $context),
        };
    }

    private function getSchema(array $items, array $targetLocales): array
    {
        $itemsSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($items),
            'properties' => array_map(fn() => ['type' => 'string'], $items),
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $targetLocales,
            'properties' => array_fill_keys($targetLocales, $itemsSchema),
        ];
    }

    private function localeName(string $locale): string
    {
        return match ($locale) {
            Locale::Vietnamese->value => 'Vietnamese',
            Locale::English->value => 'English',
            Locale::Japanese->value => 'Japanese',
            Locale::Nepali->value => 'Nepali',
            Locale::Sinhala->value => 'Sinhala',
            default => throw new RuntimeException('Unknown locale: ' . $locale),
        };
    }

    private function logFailure(Response $response, string $context): void
    {
        Log::error('OpenRouter translation request failed', [
            'context' => $context,
            'status' => $response->status(),
            'response' => $response->body(),
        ]);
    }
}
