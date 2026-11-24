<?php

namespace App\Http\Requests\Admin\Group;

use App\Actions\Admin\Group\Strategies\RuleBasedStrategy;
use App\Enums\GroupKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class AbstractGroupRequest extends FormRequest
{
    public function messages(): array
    {
        return [
            'predicate.min' => 'At least one condition is required.',
            'predicate.required' => 'At least one condition is required.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $predicate = $this->input('predicate');

        if (is_string($name)) {
            $this->merge(['name' => mb_trim($name)]);
        }

        if (is_array($predicate)) {
            $normalizedPredicate = array_map(function ($clause) {
                if (!is_array($clause)) {
                    return $clause;
                }

                $field =
                    isset($clause['field']) && is_string($clause['field'])
                        ? mb_trim($clause['field'])
                        : $clause['field'];

                $values = $this->normalizeClauseValues($clause, $field);

                return [
                    'field' => $field,
                    'values' => $values,
                ];
            }, $predicate);

            $this->merge(['predicate' => $normalizedPredicate]);
        }
    }

    protected function adminNameRules(?int $ignoreGroupId = null, bool $required = true): array
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

    protected function predicateRules(bool $required = true): array
    {
        return ['bail', $required ? 'required' : 'sometimes', 'array', 'min:1'];
    }

    protected function predicateFieldRules(): array
    {
        return ['bail', 'required', 'string', Rule::in(RuleBasedStrategy::ALLOWED_FIELDS)];
    }

    protected function predicateValueRules(): array
    {
        return ['bail', 'required', 'array', 'min:1'];
    }

    protected function predicateValueItemRules(): array
    {
        return ['bail', 'required', 'string', 'max:255'];
    }

    protected function adminKinds(): array
    {
        return [GroupKind::Manual->value, GroupKind::RuleBased->value];
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
}
