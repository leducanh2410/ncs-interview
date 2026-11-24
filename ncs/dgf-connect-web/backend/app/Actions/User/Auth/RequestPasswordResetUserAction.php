<?php

namespace App\Actions\User\Auth;

use App\Actions\ActionResult;
use App\Mail\ForgotPasswordVerifyEmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RequestPasswordResetUserAction
{
    private const OTP_EXPIRES_IN_MINUTES = 60;

    public function __invoke(string $email): ActionResult
    {
        $otp = mt_rand(100000, 999999);
        $key = 'forgot_' . Str::random();
        $values = [
            'otp' => $otp,
            'email' => $email,
        ];

        Cache::put($key, $values, now()->addMinutes(self::OTP_EXPIRES_IN_MINUTES));

        $skipResetEmails = config('auth.passwords.skip_reset_mail');
        $shouldSkip = false;
        if ($skipResetEmails) {
            $emailsToSkip = array_map('strtolower', array_map('trim', explode(',', $skipResetEmails)));
            $shouldSkip = in_array(mb_strtolower($email), $emailsToSkip, true);
        }

        if (!$shouldSkip) {
            Mail::to($email)->send(new ForgotPasswordVerifyEmail($otp, self::OTP_EXPIRES_IN_MINUTES));
        }

        return new ActionResult(
            data: [
                'key' => $key,
            ]
        );
    }
}
