<?php

namespace App\Http\Requests\Admin;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Company;
use App\Models\User;

class CreateAdminRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'login_id' => ['required', 'email', 'unique:users,login_id', 'max:255'],
            'role_name' => [
                'required',
                'string',
                Rule::enum(RoleName::class)->only([RoleName::Admin, RoleName::Doctor]),
                function ($attribute, $value, $fail) {
                    $companyId = $this->user()->hasRole(RoleName::SuperAdmin->value) ? $this->input('company_id') : $this->user()->company_id;
                    $company = Company::find($companyId);

                    if (! $company) {
                        $fail('指定された会社が見つかりません。');
                        return;
                    }

                    $managerCount = User::where('company_id', $companyId)
                        ->whereHas('roles', function ($q) {
                            $q->whereIn('name', [RoleName::Admin->value, RoleName::Doctor->value]);
                        })->count();

                    if ($managerCount >= $company->max_manager_accounts) {
                        $fail("選択された権限は、登録可能な人数の上限に達しているため登録できません。 （現在の契約枠：マネージャー{$company->max_manager_accounts}名、従業員{$company->max_employee_accounts}名）\nプランの変更や人数の追加をご希望の場合は、こちらよりお問い合わせください。");
                    }
                },
            ],
        ];

        if ($this->user()->hasRole(RoleName::SuperAdmin->value)) {
            $rules['company_id'] = ['required', 'integer', 'exists:companies,id'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'login_id' => 'Email',
        ];
    }
}
