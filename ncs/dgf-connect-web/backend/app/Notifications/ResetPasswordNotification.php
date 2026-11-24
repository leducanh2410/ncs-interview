<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    protected function resetUrl($notifiable): string
    {
        $expiration = now()->addMinutes(config('auth.passwords.users.expire'))->timestamp;

        return config('app.front_url') .
            '/auth/password/reset?token=' .
            $this->token .
            '&email=' .
            $notifiable->getEmailForPasswordReset() .
            '&expired_at=' .
            $expiration;
    }
}
