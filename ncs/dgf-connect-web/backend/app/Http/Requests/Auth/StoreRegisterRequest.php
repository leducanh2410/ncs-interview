<?php

namespace App\Http\Requests\Auth;

use App\Enums\Gender;
use App\Enums\JapaneseLevel;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;
use App\Rules\UniquePassportNumber;
use App\Rules\UniqueResidenceNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'key' => ['required', 'string'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name_kana' => ['required', 'string', 'max:255'],
            'last_name_kana' => ['required', 'string', 'max:255'],
            'birthday' => ['nullable', 'dateFormat:Y-m-d'],
            'gender' => ['nullable', Rule::in(Gender::values())],
            'nationality' => ['nullable', Rule::in(Nationality::values())],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'prefecture' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'japanese_level' => ['nullable', Rule::in(JapaneseLevel::values())],
            'registered_location' => ['nullable', 'string', 'max:255'],
            'residence_number_1' => ['nullable', 'string', 'max:12', 'min:12', new UniqueResidenceNumber()],
            'residence_expiry_date_1' => ['nullable', 'dateFormat:Y-m-d'],
            'residence_status_1' => ['nullable', Rule::in(ResidenceStatus::values())],
            'passport_number' => ['nullable', 'string', 'max:255', new UniquePassportNumber()],
            'passport_expiry_date' => ['nullable', 'dateFormat:Y-m-d'],
            'is_household_head' => ['nullable', 'boolean'],
            'facebook_link' => ['nullable', 'string', 'max:255'],
            'line_link' => ['nullable', 'string', 'max:255'],
        ];
    }
}
