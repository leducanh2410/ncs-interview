<?php

namespace App\Http\Requests\Admin\AdminPaperUpload;

use App\Enums\UserPaperType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Only the residence card scan is in scope. The column is typed wider so a
            // second document can be added later without a schema change.
            'paper_type' => ['required', Rule::in([UserPaperType::ForeignerCardScan->value])],
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,bmp,webp,heic', 'max:10240'],
        ];
    }
}
