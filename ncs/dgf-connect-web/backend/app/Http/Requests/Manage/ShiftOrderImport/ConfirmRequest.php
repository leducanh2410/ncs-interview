<?php

namespace App\Http\Requests\Manage\ShiftOrderImport;

use App\Actions\Manage\ShiftOrderImport\ConfirmShiftOrderImportAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decisions' => ['present', 'array'],
            'decisions.*.workplace_shift_id' => ['required', 'integer'],
            'decisions.*.date' => ['required', 'date_format:Y-m-d'],
            'decisions.*.source' => [
                'required',
                Rule::in([ConfirmShiftOrderImportAction::SOURCE_FILE, ConfirmShiftOrderImportAction::SOURCE_SYSTEM]),
            ],
        ];
    }
}
