<?php

namespace App\Http\Requests\SuperAdmin\Log;

use App\Enums\LogAction;
use App\Enums\LogCategory;
use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateLogRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'actor_id' => ['nullable'],
            'actor_role' => ['required', 'array'],
            'actor_role.*' => ['required', 'string', Rule::enum(RoleName::class)],
            'action' => ['nullable', 'string', Rule::enum(LogAction::class)],
            'company_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', Rule::enum(LogCategory::class)],
            'target_id' => ['nullable', 'integer'],
            'actor_name' => ['nullable', 'string'],
        ];
    }
}
