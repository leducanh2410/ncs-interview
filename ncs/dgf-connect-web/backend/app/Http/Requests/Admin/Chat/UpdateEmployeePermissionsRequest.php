<?php

namespace App\Http\Requests\Admin\Chat;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeePermissionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'employee_can_send_message' => ['bail', 'sometimes', 'boolean'],
            'employee_can_send_image' => ['bail', 'sometimes', 'boolean'],
            'employee_can_send_file' => ['bail', 'sometimes', 'boolean'],
        ];
    }
}
