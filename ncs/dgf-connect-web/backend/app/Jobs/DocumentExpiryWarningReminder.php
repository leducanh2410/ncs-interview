<?php

namespace App\Jobs;

use App\Mail\DocumentExpiryWarningEmail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DocumentExpiryWarningReminder implements ShouldQueue
{
    use Queueable;

    /**
     * How many days ahead of the expiry date the warning goes out.
     */
    private const WARNING_DAYS = 14;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // The reminder used to probe with `Y/m/d`, which MySQL casts for a DATE column but
        // compares as plain text against a VARCHAR one — so `passport_expiry_date`, the only
        // expiry column still stored as text back then, never matched and no passport
        // reminder ever went out. It is a DATE column now, and every column is probed in the
        // one format they all match exactly.
        $expiryDate = now()->addDays(self::WARNING_DAYS)->format('Y-m-d');
        Log::info("[DocumentExpiryWarningReminder] Started. Check expiry date: {$expiryDate}");

        $users = User::query()
            ->select('users.*')
            ->join('user_infos', 'user_infos.user_id', '=', 'users.id')
            ->where(function ($query) use ($expiryDate) {
                $query->where('student_card_expiry_date', $expiryDate);
                $query->orWhere('passport_expiry_date', $expiryDate);

                $query->orWhere('residence_expiry_date_1', $expiryDate);
                $query->orWhere('residence_expiry_date_2', $expiryDate);
                $query->orWhere('residence_expiry_date_3', $expiryDate);
            })
            ->where('is_disabled', false)
            ->get();

        foreach ($users as $user) {
            Mail::to($user->email)->send(new DocumentExpiryWarningEmail($user));
        }

        Log::info('[DocumentExpiryWarningReminder] Finished. Recipients: ' . $users->count());
    }
}
