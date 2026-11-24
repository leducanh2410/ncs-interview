<?php

namespace App\Http\Requests\Manage\ShiftPlanning;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['required', 'integer'],
            'cells' => ['required', 'array', 'min:1'],
            'cells.*.user_id' => ['required', 'integer'],
            'cells.*.date' => ['required', 'dateFormat:Y-m-d'],
        ];
    }
}
