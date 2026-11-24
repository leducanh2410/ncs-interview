<?php

namespace App\Observers;

use App\Actions\User\SyncShortNameAction;
use App\Models\User;

class UserShortNameObserver
{
    public function __construct(private readonly SyncShortNameAction $syncShortName) {}

    /**
     * `short_name` is derived from the person's name and nationality. Nationality
     * changes are handled by UserInfoShortNameObserver; this covers the other
     * half: when first_name/last_name change (e.g. admin edit, CrossStaff sync)
     * the shortened name must be recomputed too. Runs on `updated` only, since
     * at creation the info row (and thus nationality) does not exist yet — the
     * UserInfo observer handles the initial value.
     */
    public function updated(User $user): void
    {
        if (!$user->wasChanged(['first_name', 'last_name'])) {
            return;
        }

        // Query the relation fresh rather than reading $user->info: a cached
        // relation left over from earlier in the same request (e.g. the info
        // row was just deleted) would otherwise report stale nationality.
        ($this->syncShortName)($user, $user->info()->value('nationality'));
    }
}
