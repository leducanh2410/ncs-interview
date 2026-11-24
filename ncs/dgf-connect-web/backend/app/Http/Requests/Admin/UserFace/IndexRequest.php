<?php

namespace App\Http\Requests\Admin\UserFace;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string'],
            'staff_no' => ['nullable', 'string'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
