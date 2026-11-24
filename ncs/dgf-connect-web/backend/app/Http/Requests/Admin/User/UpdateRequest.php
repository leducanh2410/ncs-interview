<?php

namespace App\Http\Requests\Admin\User;

use App\Enums\Gender;
use App\Enums\JapaneseLevel;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;
use App\Rules\UniquePassportNumber;
use App\Rules\UniqueResidenceNumber;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = $this->route('id') ?? null;

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name_kana' => ['required', 'string', 'max:255'],
            'last_name_kana' => ['required', 'string', 'max:255'],
            'birthday' => ['nullable', 'dateFormat:Y-m-d'],
            'gender' => ['nullable', Rule::in(Gender::values())],
            'nationality' => ['nullable', Rule::in(Nationality::values())],
            'phone' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('id')),
            ],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'prefecture' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'registered_location' => ['nullable', 'string', 'max:255'],
            'staff_no' => ['nullable', 'string', 'max:255', Rule::unique('users', 'staff_no')->ignore($userId)],
            'japanese_level' => ['nullable', Rule::in(JapaneseLevel::values())],
            'residence_number_1' => ['nullable', 'string', 'max:12', 'min:12', new UniqueResidenceNumber($userId)],
            'residence_expiry_date_1' => ['nullable', 'dateFormat:Y-m-d'],
            'residence_status_1' => ['nullable', Rule::in(ResidenceStatus::values())],
            'residence_number_2' => ['nullable', 'string', 'max:12', 'min:12', new UniqueResidenceNumber($userId)],
            'residence_expiry_date_2' => ['nullable', 'dateFormat:Y-m-d'],
            'residence_status_2' => ['nullable', Rule::in(ResidenceStatus::values())],
            'residence_number_3' => ['nullable', 'string', 'max:12', 'min:12', new UniqueResidenceNumber($userId)],
            'residence_expiry_date_3' => ['nullable', 'dateFormat:Y-m-d'],
            'residence_status_3' => ['nullable', Rule::in(ResidenceStatus::values())],
            'passport_number' => ['nullable', 'string', 'max:255', new UniquePassportNumber($userId)],
            'passport_expiry_date' => ['nullable', 'dateFormat:Y-m-d'],
            'student_card_expiry_date' => [
                $this->latestResidenceStatusIsStudyAbroad() ? 'required' : 'nullable',
                'dateFormat:Y-m-d',
            ],
            'is_household_head' => ['nullable', 'boolean'],
            'is_test' => ['nullable', 'boolean'],
            'facebook_link' => ['nullable', 'string', 'max:255'],
            'line_link' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function latestResidenceStatusIsStudyAbroad(): bool
    {
        $cards = [];
        for ($slot = 1; $slot <= ResidenceSlotResolver::SLOT_COUNT; $slot++) {
            $cards[$slot] = [
                'number' => $this->input("residence_number_{$slot}"),
                'expiry' => $this->input("residence_expiry_date_{$slot}"),
                'status' => $this->input("residence_status_{$slot}"),
            ];
        }

        $slot = ResidenceSlotResolver::latestSlotFromCards($cards);

        return $slot !== null && $cards[$slot]['status'] === ResidenceStatus::StudyAbroad->value;
    }
}
