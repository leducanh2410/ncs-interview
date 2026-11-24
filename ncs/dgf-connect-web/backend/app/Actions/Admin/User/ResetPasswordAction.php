<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Mail\PasswordResetByAdminEmail;
use App\Models\User;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ResetPasswordAction
{
    public function __invoke(User $targetUser, #[\SensitiveParameter] string $password, User $actingUser): ActionResult
    {
        if ($actingUser->id === $targetUser->id) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        /** @var Collection<int, mixed> $targetRoles */
        $targetRoles = $targetUser->relationLoaded('roles') ? $targetUser->roles : $targetUser->roles()->get(['name']);

        if (
            !$actingUser->hasRole(RoleName::GlobalAdmin->value) &&
            $targetRoles->contains(fn($role) => $role->name !== RoleName::User->value)
        ) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $targetUser->update([
            'password' => Hash::make($password),
        ]);

        try {
            Mail::to($targetUser->email)->queue(new PasswordResetByAdminEmail($targetUser, $password));
        } catch (Exception $exception) {
            Log::error('Failed to queue admin password reset email', [
                'acting_user_id' => $actingUser->id,
                'target_user_id' => $targetUser->id,
                'target_user_email' => $targetUser->email,
                'exception' => $exception->getMessage(),
            ]);
        }

        return new ActionResult(data: null);
    }
}
