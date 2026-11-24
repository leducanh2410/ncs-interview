<?php

namespace App\Http\Requests\Manage\Notification;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'dates' => ['nullable', 'array', 'size:2'],
            'dates.*' => ['bail', 'string', 'date_format:Y-m-d'],
            'type' => ['bail', 'string'],
            'status' => ['bail', 'string'],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
