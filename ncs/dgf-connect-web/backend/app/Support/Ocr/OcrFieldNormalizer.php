<?php

namespace App\Support\Ocr;

use App\Actions\Ocr\ExtractJapanesePrefectureAction;
use App\Enums\BankAccountType;
use App\Enums\Gender;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;
use App\Services\External\OpenRouterOcrService;
use App\Support\BankAccount\BankAccountValueNormalizer;
use Carbon\Carbon;
use Normalizer;
use Throwable;

class OcrFieldNormalizer
{
    private const RESIDENCE_NUMBER_PATTERN = '/^[A-Z]{2}[0-9]{8}[A-Z]{2}$/';

    private ExtractJapanesePrefectureAction $extractJapanesePrefecture;

    public function __construct(?ExtractJapanesePrefectureAction $extractJapanesePrefecture = null)
    {
        $this->extractJapanesePrefecture = $extractJapanesePrefecture ?? new ExtractJapanesePrefectureAction();
    }

    /**
     * @return array{fields: array, warnings: string[]}
     */
    public function normalize(string $group, array $raw): array
    {
        $warnings = [];

        $fields = match ($group) {
            OpenRouterOcrService::GROUP_RESIDENCE_CARD,
            OpenRouterOcrService::GROUP_RESIDENCE_CARD_SCAN
                => $this->normalizeResidenceCard($raw, $warnings),
            OpenRouterOcrService::GROUP_PASSPORT => $this->normalizePassport($raw, $warnings),
            OpenRouterOcrService::GROUP_STUDENT_CARD => $this->normalizeStudentCard($raw, $warnings),
            OpenRouterOcrService::GROUP_BANK_ACCOUNT => $this->normalizeBankAccount($raw, $warnings),
            default => $raw,
        };

        return ['fields' => $fields, 'warnings' => $warnings];
    }

    private function normalizeResidenceCard(array $raw, array &$warnings): array
    {
        $address = $this->normalizeString($raw['address'] ?? null);
        $prefectureRaw = $this->normalizeString($raw['prefecture'] ?? null);
        $prefecture = ($this->extractJapanesePrefecture)($prefectureRaw, $address);
        if ($prefectureRaw !== null && $prefecture === null) {
            $warnings[] = 'prefecture_invalid_value';
        }

        $statusRaw = $this->normalizeString($raw['residence_status_raw'] ?? null);
        $status = ResidenceStatus::fromCrossStaffString($statusRaw);
        if ($statusRaw !== null && $status === null) {
            $warnings[] = 'residence_status_unmapped';
        }

        $residenceNumber = $this->normalizeString($raw['residence_number'] ?? null);
        if ($residenceNumber !== null) {
            $residenceNumber = mb_strtoupper(str_replace([' ', '-'], '', $residenceNumber));
            if (!preg_match(self::RESIDENCE_NUMBER_PATTERN, $residenceNumber)) {
                $warnings[] = 'residence_number_format';
            }
        }

        return [
            'name' => $this->normalizeName($raw['name'] ?? null),
            'last_name_kana' => $this->normalizeKanaName($raw['last_name_kana'] ?? null),
            'first_name_kana' => $this->normalizeKanaName($raw['first_name_kana'] ?? null),
            'birthday' => $this->normalizeDate($raw['birthday'] ?? null, 'birthday', $warnings),
            'gender' => $this->normalizeEnum($raw['gender'] ?? null, Gender::values(), 'gender', $warnings),
            'nationality' => $this->normalizeEnum(
                $raw['nationality'] ?? null,
                Nationality::values(),
                'nationality',
                $warnings
            ),
            'address' => $address,
            'prefecture' => $prefecture,
            'residence_number' => $residenceNumber,
            'residence_expiry_date' => $this->normalizeDate(
                $raw['residence_expiry_date'] ?? null,
                'residence_expiry_date',
                $warnings
            ),
            'residence_status' => $status,
            'residence_status_raw' => $statusRaw,
        ];
    }

    private function normalizePassport(array $raw, array &$warnings): array
    {
        $passportNumber = $this->normalizeString($raw['passport_number'] ?? null);
        if ($passportNumber !== null) {
            $passportNumber = mb_strtoupper(str_replace(' ', '', $passportNumber));
        }

        return [
            'passport_number' => $passportNumber,
            'passport_expiry_date' => $this->normalizeDate(
                $raw['passport_expiry_date'] ?? null,
                'passport_expiry_date',
                $warnings
            ),
            'name' => $this->normalizeName($raw['name'] ?? null),
            'birthday' => $this->normalizeDate($raw['birthday'] ?? null, 'birthday', $warnings),
        ];
    }

    private function normalizeStudentCard(array $raw, array &$warnings): array
    {
        return [
            'student_card_expiry_date' => $this->normalizeDate(
                $raw['student_card_expiry_date'] ?? null,
                'student_card_expiry_date',
                $warnings
            ),
            'name' => $this->normalizeName($raw['name'] ?? null),
            'school_name' => $this->normalizeString($raw['school_name'] ?? null),
        ];
    }

    private function normalizeBankAccount(array $raw, array &$warnings): array
    {
        return [
            'bank_name' => $this->normalizeString($raw['bank_name'] ?? null),
            'bank_code' => $this->normalizeDigits($raw['bank_code'] ?? null, 4, 'bank_code', $warnings),
            'branch_name' => $this->normalizeString($raw['branch_name'] ?? null),
            'branch_code' => $this->normalizeDigits($raw['branch_code'] ?? null, 3, 'branch_code', $warnings),
            'account_type' => $this->normalizeEnum(
                $raw['account_type'] ?? null,
                BankAccountType::values(),
                'account_type',
                $warnings
            ),
            'account_number' => $this->normalizeDigits($raw['account_number'] ?? null, 7, 'account_number', $warnings),
            'holder_name_kana' => BankAccountValueNormalizer::kana($raw['holder_name_kana'] ?? null),
            'holder_name_latin' => $this->normalizeName($raw['holder_name_latin'] ?? null),
            'is_yucho' => ($raw['is_yucho'] ?? null) === true,
            'yucho_symbol' => $this->normalizeDigits($raw['yucho_symbol'] ?? null, 5, 'yucho_symbol', $warnings),
            'yucho_number' => $this->normalizeDigits($raw['yucho_number'] ?? null, 8, 'yucho_number', $warnings),
        ];
    }

    private function normalizeDigits(mixed $value, int $length, string $field, array &$warnings): ?string
    {
        if ($this->normalizeString(is_int($value) ? (string) $value : $value) === null) {
            return null;
        }

        $digits = BankAccountValueNormalizer::digits($value, $length);
        if ($digits === null) {
            $warnings[] = $field . '_format';
        }

        return $digits;
    }

    private function normalizeDate(mixed $value, string $field, array &$warnings): ?string
    {
        $value = $this->normalizeString($value);
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            $warnings[] = $field . '_unparseable_date';

            return null;
        }
    }

    private function normalizeEnum(mixed $value, array $allowed, string $field, array &$warnings): ?string
    {
        $value = $this->normalizeString($value);
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower($value);
        if (!in_array($value, $allowed, true)) {
            $warnings[] = $field . '_invalid_value';

            return null;
        }

        return $value;
    }

    private function normalizeName(mixed $value): ?string
    {
        $value = $this->normalizeString($value);
        if ($value === null) {
            return null;
        }

        // Mirror the User model name mutators so comparison against DB values is fair
        return mb_strtoupper(preg_replace('/\s+/u', ' ', $value));
    }

    private function normalizeKanaName(mixed $value): ?string
    {
        $value = $this->normalizeString($value);
        if ($value === null) {
            return null;
        }

        // K: half-width katakana → full-width, V: combine voiced sound marks,
        // C: hiragana → katakana, s: full-width space → half-width space
        $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        $value = mb_convert_kana($value, 'KVCs');

        return preg_replace('/\s+/u', ' ', mb_trim($value));
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }
}
