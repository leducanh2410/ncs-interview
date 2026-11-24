<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\User;

class RestoreDeletedUserAction
{
    public function __invoke(User $user): ActionResult
    {
        $originalEmail = User::originalEmailFromDeleted($user->email);
        if (!$originalEmail) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        if (User::query()->where('email', $originalEmail)->exists()) {
            return new ActionResult(error: ErrorCode::UserEmailAlreadyExists, status: 400);
        }

        $user->email = $originalEmail;
        $user->is_disabled = false;
        $user->save();

        return new ActionResult(data: $user);
    }
}
