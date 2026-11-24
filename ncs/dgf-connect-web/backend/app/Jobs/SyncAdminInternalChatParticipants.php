<?php

namespace App\Jobs;

use App\Actions\Chat\AdminGroup\SyncAdminInternalParticipantsAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly reconciliation of the internal admin group.
 *
 * The observers and Laratrust listeners already sync on every role or account change; this is
 * the net underneath them, catching the writes they cannot see — a pivot row inserted straight
 * through the query builder by a seeder or a data fix.
 */
class SyncAdminInternalChatParticipants implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public int $timeout = 600;

    public function handle(SyncAdminInternalParticipantsAction $sync): void
    {
        $sync();
    }
}
