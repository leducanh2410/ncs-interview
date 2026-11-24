<?php

namespace App\Http\Requests\Admin\Group;

use App\Models\User;
use Closure;

class AttachMemberRequest extends AbstractGroupRequest
{
    public function rules(): array
    {
        return [
            'user_id' => [
                'bail',
                'required',
                'integer',
                'exists:users,id',
                function (string $attribute, mixed $value, Closure $fail) {
                    $user = User::query()->find($value);
                    if ($user?->is_disabled) {
                        $fail('Locked users cannot be added to groups.');
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge([
            'user_id' => $this->route('userId'),
        ]);
    }
}
