<?php

namespace App\Http\Requests\Manage\AbsentNotice;

use App\Enums\AbsentNoticeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(AbsentNoticeStatus::class)],
        ];
    }
}
