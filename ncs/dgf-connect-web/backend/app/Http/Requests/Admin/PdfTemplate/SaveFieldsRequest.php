<?php

namespace App\Http\Requests\Admin\PdfTemplate;

use App\Enums\PdfTemplateFieldFillMode;
use App\Enums\PdfTemplateFieldShape;
use App\Services\Admin\PdfTemplate\DataSourceResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFieldsRequest extends FormRequest
{
    public function rules(): array
    {
        $dataSourceKeys = app(DataSourceResolver::class)->getAll();
        $validKeys = array_column($dataSourceKeys, 'key');

        return [
            'fields' => ['required', 'array'],
            'fields.*.id' => ['nullable', 'integer'],
            'fields.*.page' => ['required', 'integer', 'min:1'],
            'fields.*.shape' => ['required', 'string', Rule::in(PdfTemplateFieldShape::values())],
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:100'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:100'],
            'fields.*.width' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'fields.*.height' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'fields.*.data_source_key' => ['required', 'string', Rule::in($validKeys)],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.invert_value' => ['boolean'],
            'fields.*.fill_mode' => ['required', 'string', Rule::in(PdfTemplateFieldFillMode::values())],
            'fields.*.text_config' => ['nullable', 'required_if:fields.*.fill_mode,text', 'array'],
            'fields.*.text_config.font_weight' => [
                'required_with:fields.*.text_config',
                'string',
                Rule::in(['normal', 'bold']),
            ],
            'fields.*.text_config.font_style' => ['nullable', 'string', Rule::in(['normal', 'italic'])],
            'fields.*.text_config.font_size' => ['required_with:fields.*.text_config', 'integer', 'min:1', 'max:72'],
            'fields.*.text_config.h_align' => [
                'required_with:fields.*.text_config',
                'string',
                Rule::in(['left', 'center', 'right']),
            ],
            'fields.*.text_config.v_align' => [
                'required_with:fields.*.text_config',
                'string',
                Rule::in(['top', 'middle', 'bottom']),
            ],
            'fields.*.text_config.letter_spacing' => ['required_with:fields.*.text_config'],
            'fields.*.text_config.overflow' => [
                'required_with:fields.*.text_config',
                'string',
                Rule::in(['wrap', 'trim', 'shrink']),
            ],
            'fields.*.shape_config' => ['nullable', 'required_if:fields.*.fill_mode,shape', 'array'],
            'fields.*.shape_config.fill_style' => [
                'required_with:fields.*.shape_config',
                'string',
                Rule::in(['filled', 'outlined']),
            ],
            'fields.*.shape_config.color' => [
                'required_with:fields.*.shape_config',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],
            'fields.*.shape_config.stroke_width' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
        ];
    }
}
