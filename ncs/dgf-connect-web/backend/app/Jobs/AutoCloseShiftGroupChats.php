<?php

namespace App\Jobs;

use App\Actions\Chat\Lifecycle\AutoCloseConversationAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Models\ChatConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Auto-closes shift-group chats a configurable number of days after their shift ends.
 *
 * The shift end is derived from the shift date + workplace shift end_time via
 * Shift::getTimeRange(), which already rolls overnight shifts (end_time <= start_time)
 * onto the next day. A chat is closed once now() has passed shiftEnd + auto_close_days.
 */
class AutoCloseShiftGroupChats implements ShouldQueue
{
    use Queueable;

    public function handle(AutoCloseConversationAction $autoClose): void
    {
        $days = (int) config('chat.shift_group.auto_close_days');

        ChatConversation::query()
            ->where('type', ChatConversationType::ShiftGroup->value)
            ->where('status', ChatConversationStatus::Open->value)
            ->whereNotNull('shift_id')
            ->with('shift.workplaceShift')
            ->chunkById(100, function ($conversations) use ($autoClose, $days) {
                foreach ($conversations as $conversation) {
                    $shift = $conversation->shift;

                    if ($shift === null || $shift->workplaceShift === null) {
                        Log::warning('Skipping shift-group chat auto-close: missing shift or workplace shift', [
                            'conversation_id' => $conversation->id,
                            'shift_id' => $conversation->shift_id,
                        ]);

                        continue;
                    }

                    [, $end] = $shift->getTimeRange();
                    $closeAt = $end->copy()->addDays($days);

                    if (now()->lt($closeAt)) {
                        continue;
                    }

                    $conversation->forceFill(['auto_close_at' => $closeAt])->save();
                    $autoClose($conversation, 'auto_closed_shift_ended');
                }
            });
    }
}
