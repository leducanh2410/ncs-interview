<?php

namespace App\Jobs;

use App\Actions\Chat\Lifecycle\DeleteClosedSupportChatsAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteClosedSupportChats implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Caps the unique-lock TTL so a SIGKILL/OOM worker does not block the next
    // scheduled run indefinitely. Requires an atomic-lock-capable cache driver.
    public int $uniqueFor = 3600;

    public int $timeout = 3500;

    public function handle(DeleteClosedSupportChatsAction $action): void
    {
        $action();
    }
}
