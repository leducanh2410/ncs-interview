<?php

namespace App\Http\Requests\User\BankAccount;

use Illuminate\Foundation\Http\FormRequest;

class UploadBankAccountImagesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:' . (int) config('bank_account.max_images')],
            'files.*' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp,heic',
                'max:' . (int) config('bank_account.max_image_size_kb'),
            ],
        ];
    }
}
