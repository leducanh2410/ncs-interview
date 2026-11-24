<?php

namespace App\Actions\Manage\Auth;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Services\TurnstileService;
use Illuminate\Support\Facades\Password;

class RequestPasswordResetAction
{
    public function __construct(private readonly TurnstileService $turnstile) {}

    public function __invoke(string $turnstileToken, string $email, ?string $remoteIp = null): ActionResult
    {
        if (!$this->turnstile->verify($turnstileToken, $remoteIp)) {
            return new ActionResult(error: ErrorCode::AuthRequestPasswordResetCaptchaFailed, status: 400);
        }

        $skipResetEmails = config('auth.passwords.skip_reset_mail');
        if ($skipResetEmails) {
            $emailsToSkip = array_map('trim', explode(',', $skipResetEmails));
            $emailsToSkip = array_map('strtolower', $emailsToSkip);
            if (in_array(mb_strtolower($email), $emailsToSkip, true)) {
                return new ActionResult(data: Password::RESET_LINK_SENT);
            }
        }

        $status = Password::sendResetLink(['email' => $email]);
        if ($status !== Password::RESET_LINK_SENT) {
            if ($status === Password::RESET_THROTTLED) {
                return new ActionResult(error: ErrorCode::AuthRequestPasswordResetThrottled, status: 400);
            }
            if ($status === Password::INVALID_USER) {
                return new ActionResult(error: ErrorCode::AuthRequestPasswordResetInvalidUser, status: 400);
            }

            return new ActionResult(error: ErrorCode::InternalServerError, status: 500);
        }

        return new ActionResult(data: $status);
    }
}
