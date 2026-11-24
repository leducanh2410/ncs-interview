<?php

namespace App\Http\Requests\Admin\Group;

use App\Enums\GroupKind;
use App\Models\Group;
use Illuminate\Validation\Validator;

class UpdateRequest extends AbstractGroupRequest
{
    public function rules(): array
    {
        /** @var Group|null $group */
        $group = $this->route('group');

        $predicateRules = $this->predicateRules(false);
        if ($group !== null && $group->kind !== GroupKind::RuleBased) {
            $predicateRules = ['prohibited'];
        }

        return [
            'name' => $this->adminNameRules($group?->id, false),
            'predicate' => $predicateRules,
            'predicate.*' => ['bail', 'required', 'array'],
            'predicate.*.field' => $this->predicateFieldRules(),
            'predicate.*.values' => $this->predicateValueRules(),
            'predicate.*.values.*' => $this->predicateValueItemRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (!$this->has('name') && !$this->has('predicate')) {
                $validator->errors()->add('group', 'At least one field must be provided.');
            }
        });
    }
}
