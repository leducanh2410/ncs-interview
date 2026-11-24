<?php

namespace App\Http\Requests\Admin\Group;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class BatchAttachMembersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_ids' => [
                'bail',
                'required',
                'array',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail) {
                    $lockedCount = User::query()->whereIn('id', $value)->where('is_disabled', true)->count();

                    if ($lockedCount > 0) {
                        $fail('Locked users cannot be added to groups.');
                    }
                },
            ],
            'user_ids.*' => ['bail', 'required', 'integer', 'exists:users,id'],
        ];
    }
}
