<?php

namespace App\Guards;

use App\Helpers\RequestHelper;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

class AccessTokenGuard
{
    use GuardHelpers;

    public function __construct(UserProvider $userProvider)
    {
        $this->provider = $userProvider;
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }
        $token = RequestHelper::getTokenFromRequest(request());
        if (empty($token)) {
            return null;
        }
        $user = $this->provider->retrieveByToken(null, $token);

        return $this->user = $user;
    }

    public function validate(array $credentials = []): bool
    {
        return true;
    }

    public function attempt(array $credentials = []): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);
        if (! $user) {
            return false;
        }
        if (! $this->provider->validateCredentials($user, $credentials)) {
            return false;
        }
        $this->user = $user;

        return true;
    }
}
