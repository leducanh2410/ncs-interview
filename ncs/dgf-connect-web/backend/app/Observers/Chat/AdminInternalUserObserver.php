<?php

namespace App\Observers\Chat;

use App\Actions\Chat\AdminGroup\SyncAdminInternalParticipantsAction;
use App\Models\User;

/**
 * Keeps the internal admin group in step with account status.
 *
 * Role changes are picked up by the Laratrust listeners registered in AppServiceProvider; what
 * is left is the account itself being disabled or re-enabled. Deleting a user needs no handler:
 * the participant row is removed by the foreign key.
 */
class AdminInternalUserObserver
{
    public function updated(User $user): void
    {
        if (!$user->wasChanged('is_disabled')) {
            return;
        }

        if (SyncAdminInternalParticipantsAction::defer()) {
            return;
        }

        app(SyncAdminInternalParticipantsAction::class)();
    }
}
