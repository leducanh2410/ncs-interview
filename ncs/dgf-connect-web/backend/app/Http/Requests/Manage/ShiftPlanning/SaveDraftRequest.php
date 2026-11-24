<?php

namespace App\Http\Requests\Manage\ShiftPlanning;

use App\Enums\ShiftPlanDraftAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDraftRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['required', 'integer'],
            'from' => ['required', 'dateFormat:Y-m-d'],
            'to' => ['required', 'dateFormat:Y-m-d', 'after_or_equal:from'],
            'drafts' => ['present', 'array'],
            'drafts.*.date' => ['required', 'dateFormat:Y-m-d'],
            'drafts.*.workplace_shift_id' => ['required', 'integer'],
            'drafts.*.user_id' => ['required', 'integer'],
            'drafts.*.shift_user_id' => ['nullable', 'integer'],
            'drafts.*.workplace_position_id' => ['nullable', 'integer'],
            'drafts.*.is_fixed' => ['required', 'boolean'],
            'drafts.*.action' => ['required', Rule::in(ShiftPlanDraftAction::values())],
            'drafts.*.target_fixed_total' => ['nullable', 'integer', 'min:0'],
            'drafts.*.target_temp_total' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
