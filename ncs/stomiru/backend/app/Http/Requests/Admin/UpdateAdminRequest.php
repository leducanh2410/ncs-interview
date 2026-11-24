<?php

namespace App\Http\Requests\Admin;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends FormRequest
{
    public function rules(): array
    {
        $admin = $this->route('admin');

        return [
            'last_name' => ['sometimes', 'string', 'max:100'],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'login_id' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'login_id')->ignore($admin->id)],
            'role_name' => ['sometimes', 'string', Rule::enum(RoleName::class)->only([RoleName::Admin, RoleName::Doctor])],
        ];
    }

    public function attributes(): array
    {
        return [
            'login_id' => 'Email',
        ];
    }
}
