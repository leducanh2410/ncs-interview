<?php

namespace App\Http\Requests\Manage\WorkHistory;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class CreateEditRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'check_in_date' => ['bail', 'required', 'date_format:Y-m-d'],
            'check_in_time' => ['bail', 'required', 'date_format:H:i:s'],
            'check_out_date' => ['bail', 'nullable', 'required_with:check_out_time', 'date_format:Y-m-d'],
            'check_out_time' => [
                'bail',
                'nullable',
                'required_with:check_out_date',
                'date_format:H:i:s',
                function (string $attribute, $value, $fail) {
                    if (!$value) {
                        return;
                    }
                    $checkInDate = $this->input('check_in_date');
                    $checkInTime = $this->input('check_in_time');
                    if (!$checkInDate || !$checkInTime) {
                        return;
                    }
                    $checkOutDate = $this->input('check_out_date');

                    try {
                        $checkIn = Carbon::parse("{$checkInDate} {$checkInTime}");
                        $checkOut = Carbon::parse("{$checkOutDate} {$value}");

                        if ($checkOut->lt($checkIn)) {
                            $fail('The check out time must be greater than the check in time.');
                        }
                    } catch (\Exception $e) {
                        $fail('Invalid date or time format provided.');
                    }
                },
            ],
            'break_minutes' => ['bail', 'nullable', 'integer', 'min:0'],
            'check_in_reason' => ['bail', 'nullable', 'string'],
            'check_out_reason' => ['bail', 'nullable', 'string'],
            'break_minutes_reason' => ['bail', 'nullable', 'string'],
        ];
    }

    /**
     * Rules alone can't reach this: a `nullable` custom-closure rule is skipped entirely by
     * the Validator when the field is absent from the input, so "reason required only when
     * its paired value changed" must be enforced in an `after` hook instead, which always runs.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->checkInTimeChanged() && mb_trim((string) $this->input('check_in_reason')) === '') {
                $validator->errors()->add('check_in_reason', 'A reason is required when the check-in time is changed.');
            }

            if ($this->checkOutTimeChanged() && mb_trim((string) $this->input('check_out_reason')) === '') {
                $validator
                    ->errors()
                    ->add('check_out_reason', 'A reason is required when the check-out time is changed.');
            }

            if ($this->breakMinutesChanged() && mb_trim((string) $this->input('break_minutes_reason')) === '') {
                $validator
                    ->errors()
                    ->add('break_minutes_reason', 'A reason is required when the break minutes is changed.');
            }
        });
    }

    private function checkInTimeChanged(): bool
    {
        $checkInDate = $this->input('check_in_date');
        $checkInTime = $this->input('check_in_time');
        if (!$checkInDate || !$checkInTime) {
            return false;
        }

        $checkIn = $this->route('checkIn');
        if (!$checkIn) {
            return false;
        }

        try {
            $newValue = Carbon::parse("{$checkInDate} {$checkInTime}");
        } catch (\Exception $e) {
            return false;
        }

        return $newValue->ne(Carbon::parse($checkIn->check_in_time));
    }

    private function checkOutTimeChanged(): bool
    {
        $checkIn = $this->route('checkIn');
        if (!$checkIn) {
            return false;
        }

        $oldValue = $checkIn->check_out_time ? Carbon::parse($checkIn->check_out_time) : null;

        $checkOutDate = $this->input('check_out_date');
        $checkOutTime = $this->input('check_out_time');
        if (!$checkOutDate || !$checkOutTime) {
            // An empty check-out field the caller never touched (absent from the request
            // entirely) means "leave it as is", not a change. Only a check-out key the caller
            // actually submitted - empty, with an old check-out on record - is a deletion.
            $checkOutKeySubmitted = $this->has('check_out_date') || $this->has('check_out_time');

            return $checkOutKeySubmitted && $oldValue !== null;
        }

        try {
            $newValue = Carbon::parse("{$checkOutDate} {$checkOutTime}");
        } catch (\Exception $e) {
            return false;
        }

        return !$oldValue || $oldValue->ne($newValue);
    }

    private function breakMinutesChanged(): bool
    {
        $breakMinutes = $this->input('break_minutes');
        if ($breakMinutes === null) {
            return false;
        }

        $checkIn = $this->route('checkIn');
        if (!$checkIn) {
            return false;
        }

        return (int) $checkIn->break_minutes !== (int) $breakMinutes;
    }
}
