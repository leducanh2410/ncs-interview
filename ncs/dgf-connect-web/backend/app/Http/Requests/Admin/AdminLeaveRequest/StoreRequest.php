<?php

namespace App\Http\Requests\Admin\AdminLeaveRequest;

use App\Enums\AdminLeaveType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    /**
     * The longest range a request may cover. It doubles as the cap that bounds the day-count
     * loop and the backdating window for an expense claim.
     */
    public const MAX_SPAN_DAYS = 366;

    public function rules(): array
    {
        $requiresDates = $this->requiresDateRange();

        return [
            'type' => ['required', Rule::in(AdminLeaveType::values())],
            'start_date' => [
                $requiresDates ? 'required' : 'nullable',
                'dateFormat:Y-m-d',
                // A dateless expense claim is allowed, but half a range is not: it would leave
                // the day count and the list's date filter with nothing coherent to work from.
                Rule::requiredIf(fn() => $this->filled('end_date')),
            ],
            'end_date' => [
                $requiresDates ? 'required' : 'nullable',
                'dateFormat:Y-m-d',
                'after_or_equal:start_date',
                Rule::requiredIf(fn() => $this->filled('start_date')),
            ],
            'reason' => ['required', 'string', 'max:1000'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => [
                'file',
                'max:5120',
                // `mimes`/`mimetypes` only judge the file's actual contents, so a valid PDF named
                // `receipt.exe` would otherwise pass; `extensions` constrains the client-supplied
                // name itself, and the stored file's extension is taken from that same name.
                'extensions:jpg,jpeg,png,webp,pdf',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // The dateFormat rule may have already failed on a malformed value; parsing it here
            // regardless would throw an uncaught exception instead of a clean 422.
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $startDate = $this->input('start_date');
            if (!$startDate) {
                return;
            }

            // "Today" is the Japanese calendar day, not UTC's: a request submitted at 08:00 JST
            // for today would otherwise be judged against the previous UTC date.
            $today = Carbon::now(config('app.timezone'))->startOfDay();
            $start = Carbon::parse($startDate, config('app.timezone'))->startOfDay();

            if ($this->requiresDateRange()) {
                if ($start->lt($today)) {
                    $validator->errors()->add('start_date', 'Leave can only be requested from today onwards.');
                }
            } elseif ($start->lt($today->copy()->subDays(self::MAX_SPAN_DAYS))) {
                // An expense claim documents money already spent, so the past is allowed — but
                // not unboundedly far into it.
                $validator->errors()->add('start_date', 'The expense date is too far in the past.');
            }

            $endDate = $this->input('end_date');
            if (!$endDate) {
                return;
            }

            $end = Carbon::parse($endDate, config('app.timezone'))->startOfDay();
            if ($end->gte($start) && $start->diffInDays($end) + 1 > self::MAX_SPAN_DAYS) {
                $validator->errors()->add('end_date', 'The requested period is too long.');
            }
        });
    }

    private function requiresDateRange(): bool
    {
        $type = AdminLeaveType::tryFrom((string) $this->input('type'));

        // An unknown type fails the `in` rule anyway; treating it as date-requiring here just
        // avoids a second, confusing error path.
        return $type === null || $type->requiresDateRange();
    }
}
