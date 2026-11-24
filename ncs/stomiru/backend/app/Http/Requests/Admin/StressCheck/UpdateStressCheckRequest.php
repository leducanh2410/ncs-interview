<?php

namespace App\Http\Requests\Admin\StressCheck;

use App\Enums\RoleName;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStressCheckRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'digits:4', 'min:'.now()->year],
            'start_date' => ['required', 'date_format:Y/m/d'],
            'end_date' => ['required', 'date_format:Y/m/d', 'after_or_equal:start_date'],
        ];

        if (! $this->user()->hasRole(RoleName::SuperAdmin->value)) {
            $stressCheck = $this->route('stressCheckPeriod');

            $rules['start_date'][] = function (string $attribute, mixed $value, Closure $fail) use ($stressCheck) {
                if ($value !== Carbon::parse($stressCheck->start_date)->format('Y/m/d')) {
                    if (Carbon::parse($value)->startOfDay()->isBefore(now()->startOfDay())) {
                        $fail('開始日は今日以降の日付を指定してください。');
                    }
                }
            };

            $rules['end_date'][] = function (string $attribute, mixed $value, Closure $fail) use ($stressCheck) {
                if ($value !== Carbon::parse($stressCheck->end_date)->format('Y/m/d')) {
                    if (Carbon::parse($value)->startOfDay()->isBefore(now()->startOfDay())) {
                        $fail('終了日は今日以降の日付を指定してください。');
                    }
                }
            };
        }

        return $rules;
    }
}
