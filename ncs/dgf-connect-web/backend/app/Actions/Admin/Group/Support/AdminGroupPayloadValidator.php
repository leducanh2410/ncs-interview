<?php

namespace App\Actions\Admin\Group\Support;

use App\Actions\Admin\Group\Strategies\RuleBasedStrategy;
use App\Enums\GroupKind;
use App\Models\Group;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminGroupPayloadValidator
{
    public function validateForStore(array $attributes): array
    {
        $normalized = $this->normalize($attributes);

        return Validator::make(
            $normalized,
            [
                'kind' => ['bail', 'required', 'string', Rule::in($this->adminKinds())],
                'name' => $this->adminNameRules(),
                'predicate' => [
                    'bail',
                    Rule::requiredIf(($normalized['kind'] ?? null) === GroupKind::RuleBased->value),
                    'array',
                    'min:1',
                ],
                'predicate.*' => ['bail', 'required', 'array'],
                'predicate.*.field' => $this->predicateFieldRules(),
                'predicate.*.values' => $this->predicateValueRules(),
                'predicate.*.values.*' => $this->predicateValueItemRules(),
            ],
            $this->messages()
        )->validate();
    }

    public function validateForUpdate(Group $group, array $attributes): array
    {
        $normalized = $this->normalize($attributes);
        $predicateRules = ['bail', 'sometimes', 'array', 'min:1'];

        if ($group->kind !== GroupKind::RuleBased) {
            $predicateRules = ['prohibited'];
        }

        $validator = Validator::make(
            $normalized,
            [
                'name' => $this->adminNameRules($group->id, false),
                'predicate' => $predicateRules,
                'predicate.*' => ['bail', 'required', 'array'],
                'predicate.*.field' => $this->predicateFieldRules(),
                'predicate.*.values' => $this->predicateValueRules(),
                'predicate.*.values.*' => $this->predicateValueItemRules(),
            ],
            $this->messages()
        );

        $validator->after(function ($validator) use ($normalized) {
            if (!array_key_exists('name', $normalized) && !array_key_exists('predicate', $normalized)) {
                $validator->errors()->add('group', 'At least one field must be provided.');
            }
        });

        return $validator->validate();
    }

    private function normalize(array $attributes): array
    {
        if (isset($attributes['name']) && is_string($attributes['name'])) {
            $attributes['name'] = mb_trim($attributes['name']);
        }

        if (!isset($attributes['predicate']) || !is_array($attributes['predicate'])) {
            return $attributes;
        }

        $attributes['predicate'] = array_map(function ($clause) {
            if (!is_array($clause)) {
                return $clause;
            }

            $field =
                isset($clause['field']) && is_string($clause['field']) ? mb_trim($clause['field']) : $clause['field'];

            $values = $this->normalizeClauseValues($clause, $field);

            return [
                'field' => $field,
                'values' => $values,
            ];
        }, $attributes['predicate']);

        return $attributes;
    }

    private function normalizeClauseValues(array $clause, ?string $field): array
    {
        if (isset($clause['values']) && is_array($clause['values'])) {
            return array_values(
                array_filter(
                    array_map(function ($value) use ($field) {
                        if (!is_string($value)) {
                            return null;
                        }

                        $value = mb_trim($value);
                        if ($field === 'prefecture') {
                            $value = mb_strtolower($value);
                        }

                        return $value === '' ? null : $value;
                    }, $clause['values'])
                )
            );
        }

        if (isset($clause['value']) && is_string($clause['value'])) {
            $value = mb_trim($clause['value']);
            if ($field === 'prefecture') {
                $value = mb_strtolower($value);
            }

            return $value === '' ? [] : [$value];
        }

        return [];
    }

    private function adminNameRules(?int $ignoreGroupId = null, bool $required = true): array
    {
        $uniqueRule = Rule::unique('groups', 'name')->where(function ($query) {
            $query->whereIn('kind', $this->adminKinds());
        });

        if ($ignoreGroupId !== null) {
            $uniqueRule = $uniqueRule->ignore($ignoreGroupId);
        }

        return array_values(
            array_filter([
                'bail',
                $required ? 'required' : 'sometimes',
                $required ? null : 'filled',
                'string',
                'max:255',
                $uniqueRule,
            ])
        );
    }

    private function predicateFieldRules(): array
    {
        return ['bail', 'required', 'string', Rule::in(RuleBasedStrategy::ALLOWED_FIELDS)];
    }

    private function predicateValueRules(): array
    {
        return ['bail', 'required', 'array', 'min:1'];
    }

    private function predicateValueItemRules(): array
    {
        return ['bail', 'required', 'string', 'max:255'];
    }

    private function adminKinds(): array
    {
        return [GroupKind::Manual->value, GroupKind::RuleBased->value];
    }

    private function messages(): array
    {
        return [
            'predicate.min' => 'At least one condition is required.',
            'predicate.required' => 'At least one condition is required.',
        ];
    }
}
