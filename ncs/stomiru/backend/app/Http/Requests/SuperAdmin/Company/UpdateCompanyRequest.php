<?php

namespace App\Http\Requests\SuperAdmin\Company;

use App\Enums\CompanyStatus;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['nullable', 'string', 'max:255'],
            'max_manager_accounts' => ['nullable', 'integer', 'min:1'],
            'max_employee_accounts' => ['nullable', 'integer', 'min:0'],
            'contact_last_name' => ['nullable', 'string', 'max:50'],
            'contact_first_name' => ['nullable', 'string', 'max:50'],
            'contact_phone' => ['nullable', 'regex:/^0[0-9]+$/'],
            'contact_email' => ['nullable', 'email', 'max:255', Rule::unique('companies', 'contact_email')->whereNull('deleted_at')->ignore($this->route('company')->id)],
            'postal_code' => ['nullable', 'regex:/^[0-9]+$/'],
            'prefecture' => ['nullable', 'string'],
            'address' => ['nullable', 'string', 'max:255'],
            'building_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(CompanyStatus::values())],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max_manager_accounts.min' => 'マネージャーアカウント数は1以上を入力してください',
            'max_employee_accounts.min' => '従業員アカウント数は0以上を入力してください',
        ];
    }

    /**
     * Validate that account limits are not lower than existing accounts.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $company = $this->route('company');
            $companyId = $company->id;

            if ($this->filled('max_manager_accounts')) {
                $managerCount = User::where('company_id', $companyId)
                    ->whereHas('roles', function ($q) {
                        $q->whereIn('name', [RoleName::Admin->value, RoleName::Doctor->value]);
                    })
                    ->count();

                if ($this->input('max_manager_accounts') < $managerCount) {
                    $validator->errors()->add(
                        'max_manager_accounts',
                        "既に{$managerCount}名のマネージャーアカウントが登録されているため、{$managerCount}名未満に変更することはできません"
                    );
                }
            }

            if ($this->filled('max_employee_accounts')) {
                $employeeCount = User::where('company_id', $companyId)
                    ->whereHas('roles', function ($q) {
                        $q->whereIn('name', [RoleName::StaffManager->value, RoleName::Staff->value]);
                    })
                    ->count();

                if ($this->input('max_employee_accounts') < $employeeCount) {
                    $validator->errors()->add(
                        'max_employee_accounts',
                        "既に{$employeeCount}名の従業員アカウントが登録されているため、{$employeeCount}名未満に変更することはできません"
                    );
                }
            }
        });
    }
}
