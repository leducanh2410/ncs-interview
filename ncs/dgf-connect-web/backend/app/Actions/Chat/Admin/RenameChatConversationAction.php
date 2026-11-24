<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
use App\Actions\Chat\Admin\Concerns\ChecksAdminParticipant;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Events\ChatConversationStateChanged;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RenameChatConversationAction
{
    use ChecksAdminParticipant;

    public function __invoke(User $actor, ChatConversation $conversation, array $params): ActionResult
    {
        if (!$this->isActiveAdminParticipant($actor, $conversation)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        // The internal admin group is the one thing about itself a human may still change, and
        // since every admin is a member, the name is narrowed to global admins.
        if ($conversation->isSystem() && !$actor->hasRole(RoleName::GlobalAdmin->value)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $groupTypes = [
            ChatConversationType::AdminGroup->value,
            ChatConversationType::ShiftGroup->value,
            ChatConversationType::UserGroup->value,
        ];

        if (
            !in_array($conversation->type, $groupTypes, true) ||
            $conversation->status !== ChatConversationStatus::Open->value
        ) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        $newTitle = $params['title'];

        DB::transaction(function () use ($actor, $conversation, $newTitle) {
            $conversation->forceFill(['title' => $newTitle])->save();

            ChatAuditLog::create([
                'conversation_id' => $conversation->id,
                'actor_user_id' => $actor->id,
                'action' => ChatAuditAction::ConversationRenamed->value,
                'payload' => ['title' => $newTitle],
            ]);
        });

        event(new ChatConversationStateChanged($conversation));

        return new ActionResult(
            data: [
                'id' => $conversation->id,
                'title' => $conversation->title,
            ]
        );
    }
}
