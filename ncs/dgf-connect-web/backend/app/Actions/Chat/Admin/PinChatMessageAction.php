<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
use App\Actions\Chat\Admin\Concerns\ChecksAdminParticipant;
use App\Enums\ErrorCode;
use App\Events\ChatConversationStateChanged;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PinChatMessageAction
{
    use ChecksAdminParticipant;

    public function __invoke(User $actor, ChatConversation $conversation, ChatMessage $message): ActionResult
    {
        if (!$this->isActiveAdminParticipant($actor, $conversation)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        if ($message->conversation_id !== $conversation->id) {
            return new ActionResult(error: ErrorCode::ChatMessageNotInConversation, status: 422);
        }

        DB::transaction(function () use ($actor, $conversation, $message) {
            $conversation
                ->forceFill([
                    'pinned_message_id' => $message->id,
                    'pinned_at' => now(),
                    'pinned_by_user_id' => $actor->id,
                ])
                ->save();
        });

        event(new ChatConversationStateChanged($conversation));

        $conversation->load([
            'pinnedMessage.sender:id,first_name,last_name,profile_picture,short_name',
            'pinnedMessage.attachments.attachment',
        ]);

        return new ActionResult(
            data: [
                'conversation_id' => $conversation->id,
                'pinned_message' => $conversation->pinnedMessage?->toPayloadArray(),
            ]
        );
    }
}
