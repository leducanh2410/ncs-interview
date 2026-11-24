<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Hash;

class LocalUserProvider implements UserProvider
{
    /**
     * {@inheritDoc}
     */
    public function retrieveById($identifier): ?User
    {
        return User::find($identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function retrieveByToken($identifier, $token): ?User
    {
        return User::query()
            ->whereHas('accessTokens', function ($query) use ($token) {
                $query->where('token', $token)->where('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function updateRememberToken(Authenticatable $user, $token)
    {
        // Nothing to do
    }

    /**
     * {@inheritDoc}
     */
    public function retrieveByCredentials(array $credentials): ?User
    {
        if (empty($credentials) || ! array_key_exists('email', $credentials)) {
            return null;
        }

        return User::query()->where('email', $credentials['email'])->first();
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        $plain = $credentials['password'];

        return Hash::check($plain, $user->getAuthPassword());
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[\SensitiveParameter] array $credentials,
        bool $force = false
    ): false {
        return false;
    }
}
