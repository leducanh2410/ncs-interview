<?php

namespace App\Http\Requests\Admin\Employee;

use App\Enums\EmployeeStatus;
use App\Enums\Gender;
use App\Enums\RoleName;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEmployeeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()->hasRole(RoleName::SuperAdmin->value) ? $this->input('company_id') : $this->user()->company_id;

        $rules = [
            'employee_code' => [
                'required',
                'string',
                'regex:/^[a-zA-Z0-9]+$/',
                function ($attribute, $value, $fail) use ($companyId) {
                    $trimmedValue = ltrim($value, '0');
                    if ($trimmedValue === '') {
                        $trimmedValue = '0'; // Handle all-zero codes like "0000"
                    }
                    $exists = User::where('company_id', $companyId)
                        ->whereRaw("TRIM(LEADING '0' FROM employee_code) = ?", [$trimmedValue])
                        ->exists();

                    if ($exists) {
                        $fail('従業員番号がすでに使用されています。');
                    }
                },
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'temp_password' => ['required', 'string', 'min:4'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where(function ($query) use ($companyId) {
                return $query->where('company_id', $companyId);
            })],
            'gender' => ['required', Rule::enum(Gender::class)],
            'birthday' => ['required', 'date_format:Ymd'],
            'department' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'role' => [
                'required',
                Rule::enum(RoleName::class)->only([RoleName::Staff, RoleName::StaffManager]),
                function ($attribute, $value, $fail) use ($companyId) {
                    $company = Company::find($companyId);
                    if (! $company) {
                        $fail('指定された会社が見つかりません。');

                        return;
                    }

                    $totalCount = User::where('company_id', $companyId)
                        ->whereHas('roles', function ($q) {
                            $q->whereIn('name', [RoleName::StaffManager->value, RoleName::Staff->value]);
                        })->count();

                    if ($totalCount >= $company->max_employee_accounts) {
                        $fail("選択された権限は、登録可能な人数の上限に達しているため登録できません。 （現在の契約枠：マネージャー{$company->max_manager_accounts}名、従業員{$company->max_employee_accounts}名）\nプランの変更や人数の追加をご希望の場合は、こちらよりお問い合わせください。");
                        return;
                    }
                },
            ],
            'employee_status' => ['required', Rule::enum(EmployeeStatus::class)],
        ];

        if ($this->user()->hasRole(RoleName::SuperAdmin->value)) {
            $rules['company_id'] = ['required', 'integer', 'exists:companies,id'];
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_code.regex' => '半角英数字で入力してください',
        ];
    }
}
