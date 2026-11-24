<?php

namespace App\Http\Requests\Admin\PaperReconciliation;

use App\Enums\ShiftUserStatus;
use App\Support\Papers\AdminScanFieldComparator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public const STATUS_UPLOADED = 'uploaded';

    /**
     * Verdicts SQL cannot express, because the admin side is a JSON blob. They filter
     * after enrichment, so they need something to bound the candidate set.
     */
    private const ENRICHMENT_ONLY_STATUSES = [
        AdminScanFieldComparator::VERDICT_MATCHED,
        AdminScanFieldComparator::VERDICT_MISMATCHED,
        AdminScanFieldComparator::VERDICT_NOT_COMPARABLE,
    ];

    /**
     * @return string[]
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_UPLOADED,
            AdminScanFieldComparator::VERDICT_NOT_UPLOADED,
            AdminScanFieldComparator::VERDICT_MATCHED,
            AdminScanFieldComparator::VERDICT_MISMATCHED,
            AdminScanFieldComparator::VERDICT_EMPLOYEE_DATA_MISSING,
            AdminScanFieldComparator::VERDICT_NOT_COMPARABLE,
        ];
    }

    public function rules(): array
    {
        return [
            'workplace_id' => ['nullable', 'integer'],
            'workplace_shift_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'shift_user_status' => [
                'nullable',
                Rule::in([ShiftUserStatus::Approved->value, ShiftUserStatus::Pending->value]),
            ],
            'status' => ['nullable', Rule::in(self::statuses())],
            'keyword' => ['nullable', 'string'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // A shift template alone does not identify a roster.
            if ($this->filled('workplace_shift_id') && !($this->filled('workplace_id') && $this->filled('date'))) {
                $validator->errors()->add('workplace_shift_id', 'A shift also needs a workplace and a date');
            }

            // A roster status is meaningless without a roster day.
            if ($this->filled('shift_user_status') && !$this->filled('date')) {
                $validator->errors()->add('shift_user_status', 'A roster status also needs a date');
            }

            // These compute a verdict per candidate employee before they can paginate.
            // Unbounded, that walks the whole payroll on every page request.
            if (in_array($this->input('status'), self::ENRICHMENT_ONLY_STATUSES, true) && !$this->hasNarrowing()) {
                $validator
                    ->errors()
                    ->add('status', 'This status also needs a date, a workplace, or a keyword to narrow the list');
            }
        });
    }

    private function hasNarrowing(): bool
    {
        return $this->filled('date') || $this->filled('workplace_id') || $this->filled('keyword');
    }
}
