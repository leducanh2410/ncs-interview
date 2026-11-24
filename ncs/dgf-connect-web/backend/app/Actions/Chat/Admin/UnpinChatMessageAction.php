<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
use App\Actions\Chat\Admin\Concerns\ChecksAdminParticipant;
use App\Enums\ErrorCode;
use App\Events\ChatConversationStateChanged;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnpinChatMessageAction
{
    use ChecksAdminParticipant;

    public function __invoke(User $actor, ChatConversation $conversation): ActionResult
    {
        if (!$this->isActiveAdminParticipant($actor, $conversation)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        if ($conversation->pinned_message_id === null) {
            return new ActionResult(data: ['conversation_id' => $conversation->id, 'pinned_message' => null]);
        }

        DB::transaction(function () use ($conversation) {
            $conversation
                ->forceFill([
                    'pinned_message_id' => null,
                    'pinned_at' => null,
                    'pinned_by_user_id' => null,
                ])
                ->save();
        });

        event(new ChatConversationStateChanged($conversation));

        return new ActionResult(data: ['conversation_id' => $conversation->id, 'pinned_message' => null]);
    }
}
