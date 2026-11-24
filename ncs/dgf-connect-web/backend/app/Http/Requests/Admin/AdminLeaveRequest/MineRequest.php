<?php

namespace App\Http\Requests\Admin\AdminLeaveRequest;

use Illuminate\Foundation\Http\FormRequest;

class MineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'page_size' => ['nullable', 'integer'],
        ];
    }
}
