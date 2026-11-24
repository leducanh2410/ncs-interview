<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        $payload = [
            'secret' => config('captcha.turnstile.secret_key'),
            'response' => $token,
        ];

        if ($remoteIp !== null) {
            $payload['remoteip'] = $remoteIp;
        }

        try {
            $response = Http::timeout(10)->asForm()->post(self::VERIFY_URL, $payload);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && (bool) $response->json('success');
    }
}
