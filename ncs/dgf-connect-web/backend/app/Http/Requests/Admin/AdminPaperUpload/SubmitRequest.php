<?php

namespace App\Http\Requests\Admin\AdminPaperUpload;

use App\Enums\Gender;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The confirm payload, whitelisted key by key.
 *
 * The keys are exactly what OcrFieldNormalizer produces for the residence card scan, so
 * the admin side of every comparison has the same shape as the machine read it corrects.
 */
class SubmitRequest extends FormRequest
{
    /**
     * @return string[]
     */
    public static function allowedFields(): array
    {
        return [
            'name',
            'last_name_kana',
            'first_name_kana',
            'birthday',
            'gender',
            'nationality',
            'address',
            'prefecture',
            'residence_number',
            'residence_expiry_date',
            'residence_status',
            'residence_status_raw',
        ];
    }

    public function rules(): array
    {
        return [
            'fields' => ['required', 'array'],
            'fields.name' => ['nullable', 'string', 'max:255'],
            'fields.last_name_kana' => ['nullable', 'string', 'max:255'],
            'fields.first_name_kana' => ['nullable', 'string', 'max:255'],
            'fields.birthday' => ['nullable', 'date_format:Y-m-d'],
            'fields.gender' => ['nullable', Rule::in(Gender::values())],
            'fields.nationality' => ['nullable', Rule::in(Nationality::values())],
            'fields.address' => ['nullable', 'string', 'max:255'],
            // Not Rule::in(Prefecture::values()): ExtractJapanesePrefectureAction emits the
            // prefecture's *Japanese name* ('東京都'), not the enum's canonical key ('tokyo'),
            // so the enum rule would reject the pipeline's own output. The employee confirm
            // screen validates it the same loose way (DocumentStepConfirmRules). It is a
            // display-only field in the comparison, so nothing downstream depends on its shape.
            'fields.prefecture' => ['nullable', 'string', 'max:255'],
            'fields.residence_number' => ['nullable', 'string', 'max:32'],
            'fields.residence_expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'fields.residence_status' => ['nullable', Rule::in(ResidenceStatus::values())],
            'fields.residence_status_raw' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Nested array keys are not covered by the rules above, so an unknown key would be
     * silently dropped rather than rejected. Reject it instead: a client must not be able
     * to smuggle arbitrary JSON into a record kept for compliance.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $fields = $this->input('fields');
            if (!is_array($fields)) {
                return;
            }

            $unknown = array_diff(array_keys($fields), self::allowedFields());
            foreach ($unknown as $key) {
                $validator->errors()->add('fields.' . $key, 'Unknown field: ' . $key);
            }
        });
    }
}
