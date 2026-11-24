<?php

namespace App\Http\Requests\Manage\Shift;

use App\Enums\ResidenceStatus;
use App\Enums\WorkLoadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class WeeklyWorkHoursRequest extends FormRequest
{
    public const MAX_DAYS = 35;

    public function rules(): array
    {
        return [
            'dates' => ['bail', 'required', 'array', 'size:2'],
            'dates.*' => ['bail', 'required', 'date_format:Y-m-d'],
            'staff_no' => ['bail', 'nullable', 'string', 'max:50'],
            'residence_statuses' => ['bail', 'nullable', 'array'],
            'residence_statuses.*' => ['bail', 'string', Rule::in(ResidenceStatus::values())],
            'workplace_id' => ['bail', 'nullable', 'integer'],
            'load_status' => ['bail', 'nullable', Rule::in(WorkLoadStatus::values())],
            'page' => ['bail', 'nullable', 'integer', 'min:1'],
            'page_size' => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $from = Carbon::parse($this->input('dates.0'));
                $to = Carbon::parse($this->input('dates.1'));
                if ($to->lt($from)) {
                    $validator->errors()->add('dates', 'The end date must be on or after the start date.');

                    return;
                }

                if ((int) $from->diffInDays($to) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('dates', 'The requested period is too long.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $residenceStatuses = $this->input('residence_statuses');
        if (is_string($residenceStatuses) && $residenceStatuses !== '') {
            $this->merge(['residence_statuses' => [$residenceStatuses]]);
        }
    }
}
