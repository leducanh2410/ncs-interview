<?php

namespace App\Http\Requests\Admin\AdminLeaveRequest;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class PreviewDaysRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'dateFormat:Y-m-d'],
            'end_date' => ['required', 'dateFormat:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = Carbon::parse($this->input('start_date'), config('app.timezone'))->startOfDay();
            $end = Carbon::parse($this->input('end_date'), config('app.timezone'))->startOfDay();

            // Same cap as submission, for the same reason: it bounds the day-count loop.
            if ($start->diffInDays($end) + 1 > StoreRequest::MAX_SPAN_DAYS) {
                $validator->errors()->add('end_date', 'The requested period is too long.');
            }
        });
    }
}
