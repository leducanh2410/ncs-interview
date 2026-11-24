<?php

namespace App\Http\Requests\Admin\User;

use Illuminate\Foundation\Http\FormRequest;

class ApplyOcrFieldsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ocr_result_id' => ['required', 'integer', 'exists:user_paper_ocr_results,id'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return [
            'fields' => 'fields to apply',
            'fields.*' => 'field to apply',
        ];
    }
}
