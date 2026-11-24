<?php

namespace App\Http\Requests\Manage\AbsentNotice;

use App\Enums\AbsentNoticeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'dateFormat:Y-m-d'],
            'date_to' => ['nullable', 'dateFormat:Y-m-d'],
            'workplace_id' => ['nullable', 'integer', 'exists:workplaces,id'],
            'status' => ['nullable', new Enum(AbsentNoticeStatus::class)],
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
