<?php

namespace App\Services\External;

use App\Enums\BankAccountType;
use App\Enums\Gender;
use App\Enums\Nationality;
use App\Enums\Prefecture;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

readonly class OpenRouterOcrService
{
    public const GROUP_RESIDENCE_CARD = 'residence_card';

    /**
     * The scanned copy of the residence card, read on its own so its fields can be
     * compared against the ones read from the physical card's two sides.
     */
    public const GROUP_RESIDENCE_CARD_SCAN = 'residence_card_scan';

    public const GROUP_PASSPORT = 'passport';

    public const GROUP_STUDENT_CARD = 'student_card';

    public const GROUP_BANK_ACCOUNT = 'bank_account';

    private PendingRequest $client;

    private string $model;

    private ?string $fallbackModel;

    private string $rotationModel;

    public function __construct()
    {
        $config = config('services.openrouter');
        $this->model = $config['model'];
        $this->fallbackModel = $config['fallback_model'] ?? null;
        $this->rotationModel = $config['rotation_model'];
        // No retry: the whole request must finish before the web server's 60s limit.
        $this->client = Http::baseUrl($config['base_url'])
            ->timeout((int) $config['timeout'])
            ->withToken($config['api_key']);
    }

    public function primaryModel(): string
    {
        return $this->model;
    }

    /**
     * Extract structured fields from document images.
     *
     * @param string $group one of the GROUP_* constants
     * @param array $images list of ['mime_type' => string, 'data' => base64 string]
     * @param bool $retryWithFallback re-request with the fallback model on an error response;
     *                                only safe on queued paths — a second attempt can double the
     *                                runtime past the web server's limit on synchronous requests
     * @return array{fields: array, model: string} raw extracted fields (not yet normalized) and the model that answered
     */
    public function extract(string $group, array $images, bool $retryWithFallback = false): array
    {
        $content = [['type' => 'text', 'text' => $this->getPrompt($group)]];
        foreach ($images as $image) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $image['mime_type'] . ';base64,' . $image['data'],
                ],
            ];
        }

        $response = $this->request($group, $content, $this->model, $this->fallbackModel);

        if (!$response->successful() && $retryWithFallback && $this->fallbackModel !== null) {
            Log::warning('OpenRouter OCR primary model failed, retrying with fallback model', [
                'group' => $group,
                'status' => $response->status(),
                'fallback_model' => $this->fallbackModel,
            ]);

            $response = $this->request($group, $content, $this->fallbackModel, null);
        }

        if (!$response->successful()) {
            Log::error('OpenRouter OCR request failed', [
                'group' => $group,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new RuntimeException('OCR provider request failed');
        }

        $text = Arr::get($response->json(), 'choices.0.message.content');
        $fields = is_string($text) ? json_decode($text, true) : null;

        if (!is_array($fields)) {
            Log::error('OpenRouter OCR returned invalid JSON', [
                'group' => $group,
                'content' => $text,
            ]);

            throw new RuntimeException('OCR provider returned invalid JSON');
        }

        return [
            'fields' => $fields,
            'model' => Arr::get($response->json(), 'model') ?: $this->model,
        ];
    }

    /**
     * Detect how far a document photo is rotated, using a cheap vision model.
     *
     * @param array{mime_type: string, data: string} $image base64-encoded image
     * @return int degrees (0, 90, 180 or 270) the image must be rotated clockwise to become upright
     */
    public function detectRotation(array $image): int
    {
        $prompt = implode(' ', [
            'The image is a photo of an identity document (residence card, passport or student card).',
            'Determine how many degrees the image must be rotated CLOCKWISE so the main printed text',
            'of the document reads upright, left to right.',
            'Return ONLY the JSON object described by the schema; rotation is 0 when the document is already upright.',
        ]);

        $response = $this->client->post('chat/completions', [
            'model' => $this->rotationModel,
            'temperature' => 0,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $image['mime_type'] . ';base64,' . $image['data'],
                            ],
                        ],
                    ],
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'rotation',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['rotation'],
                        'properties' => [
                            'rotation' => ['type' => 'integer', 'enum' => [0, 90, 180, 270]],
                        ],
                    ],
                ],
            ],
        ]);

        if (!$response->successful()) {
            Log::error('OpenRouter rotation detection failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new RuntimeException('Rotation detection request failed');
        }

        $text = Arr::get($response->json(), 'choices.0.message.content');
        $decoded = is_string($text) ? json_decode($text, true) : null;
        $rotation = is_array($decoded) ? $decoded['rotation'] ?? null : null;

        if (!in_array($rotation, [0, 90, 180, 270], true)) {
            Log::error('OpenRouter rotation detection returned an invalid value', ['content' => $text]);

            throw new RuntimeException('Rotation detection returned an invalid value');
        }

        return $rotation;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function completeJson(
        string $name,
        string $prompt,
        array $schema,
        ?string $model = null,
        ?int $timeout = null
    ): array {
        $client = $timeout !== null ? $this->client->timeout($timeout) : $this->client;
        $response = $client->post('chat/completions', [
            'model' => $model ?: $this->model,
            'temperature' => 0,
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => $prompt]]]],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $name, 'strict' => true, 'schema' => $schema],
            ],
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('OpenRouter request failed with status ' . $response->status());
        }

        $text = Arr::get($response->json(), 'choices.0.message.content');
        $decoded = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenRouter returned invalid JSON');
        }

        return $decoded;
    }

    private function request(string $group, array $content, string $model, ?string $fallbackModel): Response
    {
        $payload = [
            'model' => $model,
            'temperature' => 0,
            'messages' => [['role' => 'user', 'content' => $content]],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $group,
                    'strict' => true,
                    'schema' => $this->getSchema($group),
                ],
            ],
        ];

        if ($fallbackModel !== null) {
            // OpenRouter routes to the next model in the list when the primary provider errors (5xx, 429, ...)
            $payload['models'] = [$model, $fallbackModel];
        }

        return $this->client->post('chat/completions', $payload);
    }

    private function getPrompt(string $group): string
    {
        $common = implode(' ', [
            'You are extracting data from photos of an identity document issued in Japan.',
            'Return ONLY the JSON object described by the schema.',
            'If a field is not clearly readable on the images, return null for it. Never guess or invent values.',
            'All dates must be Gregorian calendar in YYYY-MM-DD format; convert Japanese era dates (令和/平成) if present.',
            'Names must be in Latin letters, uppercase.',
        ]);

        return match ($group) {
            self::GROUP_RESIDENCE_CARD, self::GROUP_RESIDENCE_CARD_SCAN => $common .
                ($group === self::GROUP_RESIDENCE_CARD
                    ? ' The images show the front and possibly the back of a Japanese residence card (在留カード).'
                    : ' The image is a scanned copy of a Japanese residence card (在留カード).') .
                ' last_name_kana and first_name_kana are the only exception to the Latin-letters rule:' .
                ' they are the family name and the given name(s) rendered in full-width katakana.' .
                ' They are not printed on the card: derive them from the pronunciation of the corresponding' .
                ' parts of the extracted name (this transliteration is expected, not guessing).' .
                ' On a residence card the family name is printed first. Separate words with spaces' .
                ' and return null for both only when name is null.' .
                ' residence_number is the 12-character card number, usually 2 letters + 8 digits + 2 letters.' .
                ' residence_expiry_date is the period-of-stay expiration date (在留期間満了日);' .
                ' if the back side shows a newer extended expiration date, use that one.' .
                ' residence_status_raw is the literal Japanese text of the status of residence (在留資格), e.g. 留学.' .
                ' address is the current address; if the back side lists newer addresses, use the newest one.' .
                ' prefecture is the prefecture from that address using its official Japanese name with the' .
                ' 都/道/府/県 suffix, e.g. 東京都, 大阪府, or 北海道; return null if it is uncertain.',
            self::GROUP_PASSPORT => $common .
                ' The images show pages of a passport, including the identity page.' .
                ' passport_number is the document number, passport_expiry_date is the date of expiry.' .
                ' name is the holder name as printed on the identity page; on a passport the surname' .
                ' comes first, so return it in the same order (surname then given names).' .
                ' birthday is the date of birth. Read these from the identity page only.',
            self::GROUP_STUDENT_CARD => $common .
                ' The images show a student ID card (学生証).' .
                ' student_card_expiry_date is the card validity/expiration date.' .
                ' name is the student name as printed on the card. Japanese student cards often print' .
                ' a foreign student name in katakana or in Latin letters: when it is in Latin letters' .
                ' return it as printed, and return null when only a Japanese-script form is available,' .
                ' because a transliterated name cannot be compared reliably.' .
                ' school_name is the issuing school in Japanese as printed.',
            self::GROUP_BANK_ACCOUNT => implode(' ', [
                'You are extracting bank account details from photos of a Japanese cash card (キャッシュカード)',
                'or the first page of a Japanese bank passbook (通帳).',
                'Return ONLY the JSON object described by the schema.',
                'If a field is not clearly readable on the images, return null for it. Never guess or invent values.',
                'bank_name is the financial institution name in Japanese as printed (e.g. 三菱UFJ銀行, ゆうちょ銀行).',
                'bank_code is the 4-digit institution code (金融機関コード) only when printed.',
                'branch_name is the branch name (支店名) as printed, branch_code the 3-digit branch code (店番/支店コード) only when printed.',
                'account_type is the deposit type: 普通 → ordinary, 当座 → current, 貯蓄 → savings, 定期 → time_deposit; null when not visible.',
                'account_number is the account number (口座番号) as digits only, without spaces or hyphens.',
                'holder_name_kana is the account holder name (口座名義) in full-width katakana when it is printed in katakana;',
                'holder_name_latin is the holder name when it is printed in Latin letters, uppercase. Many cash cards do not print the',
                'holder name at all: in that case return null for both, do not derive a name from anything else.',
                'is_yucho is true only for ゆうちょ銀行 (Japan Post Bank). A ゆうちょ card or passbook prints 記号 (5 digits) and',
                '番号 (up to 8 digits) instead of a branch and account number: return them in yucho_symbol and yucho_number',
                'as digits only, and leave branch_code and account_number null unless a 店番/口座番号 conversion is also printed.',
            ]),
            default => throw new RuntimeException('Unknown OCR group: ' . $group),
        };
    }

    private function getSchema(string $group): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableDate = [
            'type' => ['string', 'null'],
            'description' => 'Date in YYYY-MM-DD format, or null',
        ];

        $properties = match ($group) {
            self::GROUP_RESIDENCE_CARD, self::GROUP_RESIDENCE_CARD_SCAN => [
                'name' => $nullableString,
                'last_name_kana' => [
                    'type' => ['string', 'null'],
                    'description' => 'The family name transliterated into full-width katakana, or null',
                ],
                'first_name_kana' => [
                    'type' => ['string', 'null'],
                    'description' => 'The given name(s) transliterated into full-width katakana, or null',
                ],
                'birthday' => $nullableDate,
                'gender' => [
                    'type' => ['string', 'null'],
                    'enum' => array_merge(Gender::values(), [null]),
                ],
                'nationality' => [
                    'type' => ['string', 'null'],
                    'enum' => array_merge(Nationality::values(), [null]),
                ],
                'address' => $nullableString,
                'prefecture' => [
                    'type' => ['string', 'null'],
                    'enum' => array_merge(Prefecture::japaneseNames(), [null]),
                ],
                'residence_number' => $nullableString,
                'residence_expiry_date' => $nullableDate,
                'residence_status_raw' => $nullableString,
            ],
            self::GROUP_PASSPORT => [
                'passport_number' => $nullableString,
                'passport_expiry_date' => $nullableDate,
                'name' => $nullableString,
                'birthday' => $nullableDate,
            ],
            self::GROUP_STUDENT_CARD => [
                'student_card_expiry_date' => $nullableDate,
                'name' => $nullableString,
                'school_name' => $nullableString,
            ],
            self::GROUP_BANK_ACCOUNT => [
                'bank_name' => $nullableString,
                'bank_code' => $nullableString,
                'branch_name' => $nullableString,
                'branch_code' => $nullableString,
                'account_type' => [
                    'type' => ['string', 'null'],
                    'enum' => array_merge(BankAccountType::values(), [null]),
                ],
                'account_number' => $nullableString,
                'holder_name_kana' => $nullableString,
                'holder_name_latin' => $nullableString,
                'is_yucho' => ['type' => ['boolean', 'null']],
                'yucho_symbol' => $nullableString,
                'yucho_number' => $nullableString,
            ],
            default => throw new RuntimeException('Unknown OCR group: ' . $group),
        };

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }
}
