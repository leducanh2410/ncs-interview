<?php

namespace App\Http\Requests\Admin\Employee;

use App\Enums\EmployeeStatus;
use App\Enums\Gender;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'employee_code' => [
                'nullable',
                'string',
                'regex:/^[a-zA-Z0-9]+$/',
                function ($attribute, $value, $fail) use ($companyId) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $trimmedValue = ltrim($value, '0');
                    if ($trimmedValue === '') {
                        $trimmedValue = '0'; // Handle all-zero codes like "0000"
                    }
                    $employeeId = $this->route('employee')->id;
                    $exists = User::where('company_id', $companyId)
                        ->whereRaw("TRIM(LEADING '0' FROM employee_code) = ?", [$trimmedValue])
                        ->where('id', '!=', $employeeId)
                        ->exists();

                    if ($exists) {
                        $fail('従業員番号がすでに使用されています。');
                    }
                },
            ],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'temp_password' => ['nullable', 'string', 'min:4'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where(function ($query) use ($companyId) {
                return $query->where('company_id', $companyId);
            })->ignore($this->route('employee')->id)],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'department' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(RoleName::class)->only([RoleName::Staff, RoleName::StaffManager])],
            'employee_status' => ['nullable', Rule::enum(EmployeeStatus::class)],
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
            'employee_code.regex' => '半角英数字で入力してください',
        ];
    }
}
