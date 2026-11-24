<?php

namespace App\Actions\Common\BankAccount;

use App\Models\User;
use App\Services\External\OpenRouterOcrService;
use Illuminate\Support\Facades\Log;
use Throwable;

class JudgeSamePersonAction
{
    public const VERDICTS = ['same_person', 'different_person', 'uncertain'];

    public function __construct(private readonly OpenRouterOcrService $ocrService) {}

    /**
     * @return array{available: bool, verdict?: string, confidence?: float, reason?: string}
     */
    public function __invoke(User $user, ?string $holderKana, ?string $holderLatin): array
    {
        $prompt = implode("\n", [
            'You compare a person\'s registered name with the account holder name printed by a Japanese bank.',
            'Decide whether they plausibly denote the same person.',
            'Japanese banks store foreign names in katakana, often with the family name and given names swapped,',
            'middle names dropped, long vowels shortened, small differences in transliteration (ヴ/ブ, ティ/チ),',
            'and truncation to 30 characters. Those differences alone do not make it a different person.',
            'A clearly different family name or given name does.',
            'Registered Latin name: ' . mb_trim(($user->last_name ?? '') . ' ' . ($user->first_name ?? '')),
            'Registered katakana name: ' .
            mb_trim(($user->last_name_kana ?? '') . ' ' . ($user->first_name_kana ?? '')),
            'Nationality: ' . ($user->info?->nationality ?? 'unknown'),
            'Account holder (katakana): ' . ($holderKana ?? 'not available'),
            'Account holder (Latin): ' . ($holderLatin ?? 'not available'),
            'Return ONLY the JSON object described by the schema. reason is one short sentence in English.',
        ]);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['verdict', 'confidence', 'reason'],
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => self::VERDICTS],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'reason' => ['type' => 'string'],
            ],
        ];

        try {
            $decoded = $this->ocrService->completeJson(
                'bank_holder_name_check',
                $prompt,
                $schema,
                config('bank_account.name_check_model') ?: null,
                (int) config('bank_account.name_check_timeout')
            );
        } catch (Throwable $e) {
            Log::warning('Bank holder name AI check unavailable', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ['available' => false];
        }

        $verdict = $decoded['verdict'] ?? null;
        if (!in_array($verdict, self::VERDICTS, true)) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'verdict' => $verdict,
            'confidence' => is_numeric($decoded['confidence'] ?? null)
                ? round((float) $decoded['confidence'], 2)
                : null,
            'reason' => is_string($decoded['reason'] ?? null) ? mb_substr($decoded['reason'], 0, 300) : null,
        ];
    }
}
