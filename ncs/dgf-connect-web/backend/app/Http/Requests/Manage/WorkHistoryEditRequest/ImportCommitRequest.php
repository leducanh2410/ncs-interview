<?php

namespace App\Http\Requests\Manage\WorkHistoryEditRequest;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ImportCommitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rows' => ['bail', 'required', 'array', 'min:1'],
            'rows.*.check_in_id' => ['bail', 'required', 'integer'],
            'rows.*.items' => ['bail', 'required', 'array', 'min:1'],
            'rows.*.items.*.field' => [
                'bail',
                'required',
                Rule::in(['check_in_time', 'check_out_time', 'break_minutes']),
            ],
            'rows.*.items.*.old_value' => ['bail', 'nullable', 'string'],
            'rows.*.items.*.new_value' => ['bail', 'nullable', 'string'],
            'rows.*.decision' => ['bail', 'required', Rule::in(['apply', 'reject'])],
            // A rejection without a stated reason is not a decision the submitter can act on -
            // same requirement as the single-record reject flow (RejectRequest).
            'rows.*.reject_reason' => ['bail', 'required_if:rows.*.decision,reject', 'string', 'max:1000'],
        ];
    }

    /**
     * Whether `old_value`/`new_value` look like a real value for their sibling `field` can't be
     * expressed as a plain rule - it depends on that sibling within the same array item, the same
     * reason CreateEditRequestRequest needs an `after` hook rather than a closure rule. This
     * payload is entirely client-reconstructed (nothing here is cross-checked against the preview
     * response that produced it), so without this check a garbage string reaches
     * ApproveWorkHistoryEditRequestAction's Carbon::parse() deep inside the commit transaction and
     * surfaces as an uncaught 500 (with a rollback) instead of a 422.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('rows', []) as $rowIndex => $row) {
                foreach ((array) ($row['items'] ?? []) as $itemIndex => $item) {
                    $field = $item['field'] ?? null;
                    foreach (['old_value', 'new_value'] as $key) {
                        $value = $item[$key] ?? null;
                        $path = "rows.{$rowIndex}.items.{$itemIndex}.{$key}";

                        if ($value === null) {
                            // Unlike check_out_time (null is a real "clear the check-out" state)
                            // and break_minutes, check_in_time has no valid null meaning -
                            // check_ins.check_in_time is NOT NULL. Left unchecked,
                            // ApproveWorkHistoryEditRequestAction::buildApplyValuesData() resolves
                            // a null new_value straight into Carbon::parse(null), which returns
                            // "now" and silently overwrites the check-in time with the moment the
                            // request happened to be processed - both drift guards pass because
                            // nothing about old_value/live data actually changed.
                            if ($field === 'check_in_time') {
                                $validator->errors()->add($path, 'The value is required for check_in_time.');
                            }

                            continue;
                        }

                        if (!is_string($value)) {
                            continue;
                        }

                        if (in_array($field, ['check_in_time', 'check_out_time'], true)) {
                            if (!$this->isValidDateTimeString($value)) {
                                $validator
                                    ->errors()
                                    ->add($path, 'The value must be a valid Y-m-d H:i:s date and time.');
                            }
                        } elseif ($field === 'break_minutes' && !ctype_digit($value)) {
                            $validator->errors()->add($path, 'The value must be a non-negative integer.');
                        }
                    }
                }
            }
        });
    }

    private function isValidDateTimeString(string $value): bool
    {
        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $value)->format('Y-m-d H:i:s') === $value;
        } catch (\Exception $e) {
            return false;
        }
    }
}
