<?php

namespace App\Http\Requests\Admin\Workplace;

use App\Enums\InstructionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstructionRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'type' => ['bail', 'required', Rule::in(InstructionType::values())],
            'title' => ['bail', 'required', 'string', 'max:255'],
            'content' => ['bail', 'required', 'string', 'max:255'],
        ];

        $type = $this->input('type');
        if ($type === InstructionType::Image->value) {
            $isUpdate = $this->route('instruction') !== null;
            unset($rules['content']);
            $rules['file'] = [
                'bail',
                $isUpdate ? 'nullable' : 'required',
                'image',
                'mimes:jpg,jpeg,png,bmp,webp,heic',
                'max:8192',
            ];
        } elseif ($type === InstructionType::Link->value || $type === InstructionType::Video->value) {
            $rules['content'][] = 'url';
        }

        return $rules;
    }

    public function attributes(): array
    {
        $attributes = parent::attributes();
        $type = $this->input('type');
        if ($type === InstructionType::Link->value || $type === InstructionType::Video->value) {
            $attributes['content'] = 'url';
        }

        return $attributes;
    }
}
