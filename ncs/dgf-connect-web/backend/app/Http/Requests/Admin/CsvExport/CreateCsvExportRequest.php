<?php

namespace App\Http\Requests\Admin\CsvExport;

use App\Enums\Encoding;
use App\Enums\PermissionName;
use App\Enums\UserInfoSearchField;
use App\Services\Csv\CsvPropertyRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateCsvExportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'selected_keys' => ['required', 'array', 'min:1'],
            'selected_keys.*' => ['required', 'string'],
            'ordered_keys' => ['required', 'array', 'min:1'],
            'ordered_keys.*' => ['required', 'string'],
            'encoding' => ['nullable', 'string', Rule::in(Encoding::values())],
            'user_ids' => ['nullable', 'array'],
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

                $selectedKeys = $this->input('selected_keys', []);
                $orderedKeys = $this->input('ordered_keys', []);
                $userIds = $this->input('user_ids', []);
                $selectAll = $this->boolean('select_all');
                $registry = app(CsvPropertyRegistry::class);

                $missingSelectedKeys = array_diff($selectedKeys, $orderedKeys);
                if (!empty($missingSelectedKeys)) {
                    $validator->errors()->add('selected_keys', 'Selected keys must be included in ordered_keys.');
                }

                $unknownKeys = array_merge(
                    $registry->validateKeys($selectedKeys),
                    $registry->validateKeys($orderedKeys)
                );
                if (!empty($unknownKeys)) {
                    $validator
                        ->errors()
                        ->add(
                            'ordered_keys',
                            'Unknown CSV property keys: ' . implode(', ', array_unique($unknownKeys))
                        );
                }

                if ($selectAll === empty($userIds)) {
                    return;
                }

                $validator
                    ->errors()
                    ->add('user_ids', 'Either select_all must be true or user_ids must contain items, but not both.');
            },
        ];
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
