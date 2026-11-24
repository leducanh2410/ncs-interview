<?php

namespace App\Http\Requests\Admin\Group;

use App\Enums\GroupKind;
use Illuminate\Validation\Rule;

class StoreRuleBasedRequest extends AbstractGroupRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['bail', 'required', 'string', Rule::in([GroupKind::RuleBased->value])],
            'name' => $this->adminNameRules(),
            'predicate' => $this->predicateRules(),
            'predicate.*' => ['bail', 'required', 'array'],
            'predicate.*.field' => $this->predicateFieldRules(),
            'predicate.*.values' => $this->predicateValueRules(),
            'predicate.*.values.*' => $this->predicateValueItemRules(),
        ];
    }
}
