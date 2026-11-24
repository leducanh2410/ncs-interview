<?php

namespace App\Jobs;

use App\Actions\Chat\Lifecycle\AutoCloseConversationAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Models\ChatConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Auto-closes support chats after a configurable inactivity period.
 *
 * Inactivity is measured from last_activity_at, which only real user/admin messages
 * reset — system events (assignment, permission changes, close/reopen notices) leave it
 * untouched (see RecordSystemMessageAction), so those never extend the timer.
 */
class AutoCloseInactiveSupportChats implements ShouldQueue
{
    use Queueable;

    public function handle(AutoCloseConversationAction $autoClose): void
    {
        $threshold = now()->subHours((int) config('chat.support.auto_close_inactive_hours'));

        ChatConversation::query()
            ->whereIn('type', [ChatConversationType::Support->value, ChatConversationType::GuestSupport->value])
            ->where('status', ChatConversationStatus::Open->value)
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<=', $threshold)
            ->chunkById(100, function ($conversations) use ($autoClose) {
                foreach ($conversations as $conversation) {
                    $conversation->forceFill(['auto_close_at' => now()])->save();
                    $autoClose($conversation, 'auto_closed_inactivity');
                }
            });
    }
}
