<?php

namespace App\Http\Requests\Admin\BankAccount;

use App\Enums\PayoutMethod;
use App\Models\UserBankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'workplace_id' => ['nullable', 'array'],
            'workplace_id.*' => ['integer'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::in(UserBankAccount::reviewableStatuses())],
            'payout_method' => ['nullable', 'string', Rule::in(PayoutMethod::values())],
            'has_warning' => ['nullable', 'string', 'in:true,false,1,0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
