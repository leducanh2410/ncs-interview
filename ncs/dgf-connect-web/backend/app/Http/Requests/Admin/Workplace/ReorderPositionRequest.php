<?php

namespace App\Http\Requests\Admin\Workplace;

use Illuminate\Foundation\Http\FormRequest;

class ReorderPositionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'positions_order' => ['required', 'array'],
            'positions_order.*' => ['required', 'integer'],
        ];
    }
}
