<?php

namespace App\Actions\Manage\Auth;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class ResetPasswordAction
{
    public function __invoke(#[\SensitiveParameter] array $credentials): ActionResult
    {
        $status = Password::reset($credentials, function ($user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
            ]);

            $user->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        return new ActionResult(data: true);
    }
}
