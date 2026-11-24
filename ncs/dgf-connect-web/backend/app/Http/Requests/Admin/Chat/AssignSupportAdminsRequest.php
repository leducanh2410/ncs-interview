<?php

namespace App\Http\Requests\Admin\Chat;

use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignSupportAdminsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'admin_user_ids' => ['bail', 'required', 'array', 'min:1'],
            'admin_user_ids.*' => ['bail', 'integer', 'distinct', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $ids = collect($this->input('admin_user_ids', []))->map(fn($id) => (int) $id);
            if ($ids->isEmpty()) {
                return;
            }

            // Only active users who hold both chat.support and chat.view may be assigned.
            // chat.view is required because handlers must be able to open/read/reply to conversations.
            $eligibleIds = User::query()
                ->whereIn('id', $ids)
                ->where('is_disabled', false)
                ->get()
                ->filter(
                    fn(User $user) => $user->hasPermission(PermissionName::ChatSupport->value) &&
                        $user->hasPermission(PermissionName::ChatView->value)
                )
                ->pluck('id')
                ->map(fn($id) => (int) $id);

            if ($ids->diff($eligibleIds)->isNotEmpty()) {
                $validator->errors()->add('admin_user_ids', 'One or more users cannot be assigned to support chats.');
            }
        });
    }
}
