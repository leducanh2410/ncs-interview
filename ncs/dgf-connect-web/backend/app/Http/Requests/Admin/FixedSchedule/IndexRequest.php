<?php

namespace App\Http\Requests\Admin\FixedSchedule;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['bail', 'nullable', 'integer'],
            'user_id' => ['bail', 'nullable', 'integer'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
