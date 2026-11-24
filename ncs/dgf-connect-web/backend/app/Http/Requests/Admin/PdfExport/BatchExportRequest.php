<?php

namespace App\Http\Requests\Admin\PdfExport;

use App\Enums\PermissionName;
use App\Enums\UserInfoSearchField;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BatchExportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'template_id' => ['required', 'exists:pdf_templates,id'],
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'select_all' => ['boolean'],
            'filters' => ['nullable', 'array'],
            'filters.staff_no' => ['nullable', 'string'],
            'filters.staff_no_empty' => ['nullable'],
            'filters.name' => ['nullable', 'string'],
            'filters.email' => ['nullable', 'string'],
            'filters.workplace_id' => ['nullable', 'integer'],
            'filters.workplace_none' => ['nullable'],
            'filters.paper_status' => ['nullable', 'string'],
            'filters.is_disabled' => ['nullable', 'string'],
            'filters.role' => ['nullable', 'string'],
            'filters.face_registered' => ['nullable', 'string'],
            'filters.custom_config' => ['nullable', 'string'],
            'filters.groups' => ['nullable', 'array'],
            'filters.groups.*' => ['bail', 'integer', 'distinct', 'exists:groups,id'],
            'filters.info_search_field' => ['nullable', 'string', Rule::in(UserInfoSearchField::values())],
            'filters.info_search_value' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $userIds = $this->input('user_ids', []);
                $selectAll = $this->boolean('select_all');

                if (empty($userIds) && !$selectAll) {
                    $validator
                        ->errors()
                        ->add('user_ids', 'Either user_ids must contain items or select_all must be true.');
                }
            },
        ];
    }

    public function getResolvedUserIds(): array
    {
        if (!$this->boolean('select_all')) {
            return $this->input('user_ids', []);
        }

        $filters = $this->input('filters', []);

        if (!isset($filters['is_disabled'])) {
            $filters['is_disabled'] = false;
        }

        return User::getFilteredQuery($filters)->pluck('id')->toArray();
    }

    protected function prepareForValidation(): void
    {
        $filters = $this->input('filters');
        if (
            is_array($filters) &&
            array_key_exists('custom_config', $filters) &&
            !$this->user()?->hasPermission(PermissionName::UserConfigList->value)
        ) {
            unset($filters['custom_config']);
            $this->merge(['filters' => $filters]);
        }
    }
}
