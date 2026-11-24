<?php

namespace App\Http\Requests\User\BankAccount;

use App\Support\BankAccount\BankAccountConfirmRules;
use Illuminate\Foundation\Http\FormRequest;

class SubmitBankAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return BankAccountConfirmRules::rules($this->boolean('is_yucho'));
    }
}
