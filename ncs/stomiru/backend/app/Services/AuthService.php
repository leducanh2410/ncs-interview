<?php

namespace App\Services;

use App\Models\AccessToken;
use Carbon\Carbon;
use Illuminate\Support\Str;

class AuthService
{
    public function createToken(int $userId, int $expiresInHour = 1): AccessToken
    {
        return AccessToken::query()->create([
            'user_id' => $userId,
            'token' => Str::random(128),
            'expires_at' => Carbon::now()->addHours($expiresInHour),
        ]);
    }

    public function revokeToken(string $token): int
    {
        return AccessToken::query()->where('token', $token)->delete();
    }
}
