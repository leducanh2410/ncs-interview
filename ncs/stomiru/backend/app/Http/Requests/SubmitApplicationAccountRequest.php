<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitApplicationAccountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'max_manager_accounts' => ['required', 'integer', 'min:1'],
            'max_employee_accounts' => ['required', 'integer', 'min:0'],
            'contact_last_name' => ['required', 'string', 'max:50'],
            'contact_first_name' => ['required', 'string', 'max:50'],
            'contact_phone' => ['required', 'regex:/^0[0-9]+$/'],
            'contact_email' => ['required', 'email', Rule::unique('companies', 'contact_email')->whereNull('deleted_at'), 'max:255'],
            'postal_code' => ['required', 'regex:/^[0-9]+$/'],
            'prefecture' => ['required', 'string'],
            'address' => ['required', 'string', 'max:255'],
            'building_name' => ['required', 'string', 'max:255'],
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
            'company_name.required' => '社名を入力してください',
            'company_name.max' => '社名は255文字以内で入力してください',

            'max_manager_accounts.required' => 'マネージャーアカウント数を入力してください',
            'max_manager_accounts.integer' => 'マネージャーアカウント数は半角数字で入力してください',
            'max_manager_accounts.min' => 'マネージャーアカウント数は1以上を入力してください',

            'max_employee_accounts.required' => '従業員アカウント数を入力してください',
            'max_employee_accounts.integer' => '従業員アカウント数は半角数字で入力してください',

            'contact_last_name.required' => '姓を入力してください',
            'contact_last_name.max' => '姓は50文字以内で入力してください',

            'contact_first_name.required' => '名を入力してください',
            'contact_first_name.max' => '名は50文字以内で入力してください',

            'contact_phone.required' => '電話番号を入力してください',
            'contact_phone.regex' => '「-」を除いた半角数字で入力してください',

            'contact_email.required' => 'メールアドレスを入力してください',
            'contact_email.email' => '正しいメールアドレスを入力してください',
            'contact_email.unique' => 'このメールアドレスは既に登録されています',
            'contact_email.max' => 'メールアドレスは255文字以内で入力してください',

            'postal_code.required' => '郵便番号を入力してください',
            'postal_code.regex' => '「-」を除いた半角数字で入力してください',

            'prefecture.required' => '都道府県を選択してください',

            'address.required' => '市区町村・番地を入力してください',
            'address.max' => '住所は255文字以内で入力してください',

            'building_name.required' => '建物名を入力してください',
            'building_name.max' => '建物名は255文字以内で入力してください',
        ];
    }
}
