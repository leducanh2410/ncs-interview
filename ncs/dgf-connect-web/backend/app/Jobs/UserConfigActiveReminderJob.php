<?php

namespace App\Jobs;

use App\Actions\MonthlyReminder\CheckActiveUserConfigsAction;
use App\Mail\UserConfigActiveReminderEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class UserConfigActiveReminderJob implements ShouldQueue
{
    use Queueable;

    public function handle(CheckActiveUserConfigsAction $checkActiveUserConfigsAction): void
    {
        Log::info('[UserConfigActiveReminderJob] Started checking active user configs');

        $usersWithConfigs = $checkActiveUserConfigsAction();

        if ($usersWithConfigs->isEmpty()) {
            Log::info('[UserConfigActiveReminderJob] No users with active custom configs found');

            return;
        }

        $adminEmails = array_filter(
            config('work.system_notification_emails', []),
            fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL)
        );

        if (empty($adminEmails)) {
            Log::warning('[UserConfigActiveReminderJob] No valid admin emails configured');

            return;
        }

        Log::info(
            '[UserConfigActiveReminderJob] Found ' .
                $usersWithConfigs->count() .
                ' users with active configs. Sending email to admins.'
        );

        foreach ($adminEmails as $adminEmail) {
            try {
                Mail::to($adminEmail)->send(new UserConfigActiveReminderEmail($usersWithConfigs));
                Log::info('[UserConfigActiveReminderJob] Email sent successfully to ' . $adminEmail);
            } catch (\Exception $e) {
                Log::error('[UserConfigActiveReminderJob] Failed to send email to ' . $adminEmail, [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
