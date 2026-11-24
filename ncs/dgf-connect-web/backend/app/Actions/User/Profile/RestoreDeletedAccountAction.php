<?php

namespace App\Actions\User\Profile;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestoreDeletedAccountAction
{
    public function __invoke(string $key, string $otp): ActionResult
    {
        $cacheData = Cache::get($key);
        if (!isset($cacheData)) {
            return new ActionResult(error: ErrorCode::RestoreInvalidSession, status: 400);
        }

        if ((string) $cacheData['otp'] !== (string) $otp) {
            return new ActionResult(error: ErrorCode::RestoreInvalidOtp, status: 400);
        }

        $email = $cacheData['email'];

        $result = DB::transaction(function () use ($email): ActionResult {
            $user = User::findDisabledByOriginalEmail($email, lockForUpdate: true);
            if (!$user) {
                return new ActionResult(error: ErrorCode::RestoreAccountNotFound, status: 400);
            }

            if (User::query()->where('email', $email)->exists()) {
                return new ActionResult(error: ErrorCode::UserEmailAlreadyExists, status: 400);
            }

            $user->email = $email;
            $user->is_disabled = false;
            $user->save();

            Log::info("User {$user->id} self-restored their deleted account");

            return new ActionResult(data: $user);
        });

        if (!$result->getError()) {
            Cache::forget($key);
        }

        return $result;
    }
}
