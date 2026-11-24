<?php

namespace App\Http\Requests\Admin\Group;

use App\Enums\GroupKind;
use Illuminate\Validation\Rule;

class StoreManualRequest extends AbstractGroupRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['bail', 'required', 'string', Rule::in([GroupKind::Manual->value])],
            'name' => $this->adminNameRules(),
        ];
    }
}
