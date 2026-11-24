<?php

namespace App\Http\Requests\Manage\Shift;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'dateFormat:Y-m-d'],
            'workplace_id' => ['nullable', 'integer'],
        ];
    }
}
