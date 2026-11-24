<?php

namespace App\Http\Requests\Admin\PaperReconciliation;

use Illuminate\Foundation\Http\FormRequest;

class FilterOptionsRequest extends FormRequest
{
    public function rules(): array
    {
        // Either both or neither: the workplace list is answered on its own, but a shift
        // only exists at one workplace on one day, so half a pair is a mistake, not a
        // narrower question.
        return [
            'workplace_id' => ['nullable', 'integer', 'required_with:date'],
            'date' => ['nullable', 'date_format:Y-m-d', 'required_with:workplace_id'],
        ];
    }
}
