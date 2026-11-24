<?php

namespace App\Support\Document;

use App\Enums\Gender;
use App\Enums\JapaneseLevel;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperStepKey;
use App\Rules\UniquePassportNumber;
use App\Rules\UniqueResidenceNumber;
use Illuminate\Validation\Rule;

/**
 * Validation rules for the fields a step's confirm form submits.
 *
 * Shared by the per-step confirm endpoint and the single submit that replaces it, so the
 * two can never drift into accepting different payloads for the same document.
 */
class DocumentStepConfirmRules
{
    /**
     * @param int $userId owner of the submission, excluded from the uniqueness checks
     * @return array<string, array<int, mixed>>
     */
    public static function forStep(UserPaperStepKey $step, int $userId): array
    {
        // The client echoes the OCR run its confirm form was built from, so a confirm
        // composed before a rejection + re-upload cannot be applied to the replacing upload
        $version = ['ocr_result_id' => ['required', 'integer']];

        return match ($step) {
            UserPaperStepKey::ResidenceCard => $version + self::residenceCardRules($userId),
            UserPaperStepKey::Passport => $version + [
                'passport_number' => ['required', 'string', 'max:255', new UniquePassportNumber($userId)],
                'passport_expiry_date' => ['required', 'dateFormat:Y-m-d'],
            ],
            UserPaperStepKey::StudentCard => $version + [
                'student_card_expiry_date' => ['required', 'dateFormat:Y-m-d'],
            ],
            UserPaperStepKey::Portrait, UserPaperStepKey::WorkPermit, UserPaperStepKey::Other => [],
        };
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private static function residenceCardRules(int $userId): array
    {
        return [
            // The step's own document fields must be confirmed; the rest mirrors the profile rules
            'residence_number' => ['required', 'string', 'min:12', 'max:12', new UniqueResidenceNumber($userId)],
            'residence_expiry_date' => ['required', 'dateFormat:Y-m-d'],
            'residence_status' => ['required', Rule::in(ResidenceStatus::values())],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name_kana' => ['nullable', 'string', 'max:255'],
            'last_name_kana' => ['nullable', 'string', 'max:255'],
            'birthday' => ['nullable', 'dateFormat:Y-m-d'],
            'gender' => ['nullable', Rule::in(Gender::values())],
            'nationality' => ['nullable', Rule::in(Nationality::values())],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'prefecture' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:255'],
            'japanese_level' => ['nullable', Rule::in(JapaneseLevel::values())],
            'registered_location' => ['nullable', 'string', 'max:255'],
            'is_household_head' => ['nullable', 'boolean'],
            'facebook_link' => ['nullable', 'string', 'max:255'],
            'line_link' => ['nullable', 'string', 'max:255'],
        ];
    }
}
