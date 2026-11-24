<?php

namespace App\Actions\User\Auth;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class ResetPasswordUserAction
{
    public function __invoke(#[\SensitiveParameter] array $data): ActionResult
    {
        $cacheData = Cache::get($data['key']);
        if (!isset($cacheData)) {
            return new ActionResult(ErrorCode::AuthRequestPasswordResetInvalidSession);
        }

        if ((string) $cacheData['otp'] !== (string) $data['otp']) {
            return new ActionResult(ErrorCode::AuthRequestPasswordResetInvalidOtp);
        }

        $user = User::query()->where('email', $cacheData['email'])->first();
        if (!$user) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        Cache::forget($data['key']);

        return new ActionResult(data: true);
    }
}
