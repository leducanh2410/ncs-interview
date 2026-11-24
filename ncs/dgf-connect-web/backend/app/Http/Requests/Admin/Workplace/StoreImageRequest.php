<?php

namespace App\Http\Requests\Admin\Workplace;

use Illuminate\Foundation\Http\FormRequest;

class StoreImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'images' => ['bail', 'required', 'array', 'min:1'],
            'images.*' => ['bail', 'required', 'image', 'mimes:jpg,jpeg,png,bmp,webp,heic', 'max:8192'],
        ];
    }
}
