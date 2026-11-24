<?php

namespace App\Http\Requests\User\DocumentStep;

use App\Enums\ErrorCode;
use App\Enums\UserPaperStepKey;
use App\Rules\UniquePassportNumber;
use App\Rules\UniqueResidenceNumber;
use App\Support\Document\DocumentStepConfirmRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ClosureValidationRule;

/**
 * The whole submission in one payload: every step that needs confirming, keyed by step.
 *
 * Confirming step by step could not enforce that picking 留学 also brings the student card
 * and the work permit — the residence card was already written by the time the client knew
 * what else was needed. Submitting together makes the set of documents atomic.
 */
class SubmitDocumentStepsRequest extends ErrorCodedFormRequest
{
    public function rules(): array
    {
        $confirmableKeys = array_values(
            array_map(
                fn(UserPaperStepKey $key) => $key->value,
                array_filter(UserPaperStepKey::cases(), fn(UserPaperStepKey $key) => $key->ocrGroup() !== null)
            )
        );

        $rules = [
            'steps' => ['required', 'array', 'min:1'],
            'steps.*' => ['required', 'array'],
        ];

        foreach (array_keys((array) $this->input('steps', [])) as $key) {
            if (!is_string($key) || !in_array($key, $confirmableKeys, true)) {
                // Reported by the key rule below; do not build field rules for an unknown step
                continue;
            }

            foreach (DocumentStepConfirmRules::forStep(UserPaperStepKey::from($key), Auth::id()) as $field => $rule) {
                $rules["steps.{$key}.{$field}"] = $rule;
            }
        }

        // Only steps that carry confirmable fields belong here; image-only steps
        // (portrait, work permit) are complete as soon as they are uploaded
        $rules['steps'][] = function (string $attribute, mixed $value, callable $fail) use ($confirmableKeys) {
            foreach (array_keys((array) $value) as $key) {
                if (!in_array($key, $confirmableKeys, true)) {
                    $fail(__('validation.in', ['attribute' => $attribute . '.' . $key]));
                }
            }
        };

        return $rules;
    }

    protected function errorCodeFor(string $attribute, string $rule, mixed $value): ErrorCode
    {
        return match (true) {
            $rule === ClosureValidationRule::class => ErrorCode::UserPaperStepNotConfirmable,
            $rule === UniquePassportNumber::class => ErrorCode::RegisterPassportNumberAlreadyExists,
            $rule === UniqueResidenceNumber::class => ErrorCode::RegisterResidenceNumberAlreadyExists,
            $rule === 'Required' => ErrorCode::UserPaperFieldRequired,
            $rule === 'DateFormat' => ErrorCode::UserPaperFieldInvalidDate,
            in_array($rule, ['Min', 'Max'], true) && Str::endsWith($attribute, '.residence_number')
                => ErrorCode::UserPaperResidenceNumberInvalidLength,
            $rule === 'Max' => ErrorCode::UserPaperFieldTooLong,
            default => ErrorCode::UserPaperFieldInvalidValue,
        };
    }

    protected function errorCodeForCustomFailure(string $attribute): ErrorCode
    {
        return ErrorCode::UserPaperFieldInvalidValue;
    }
}
