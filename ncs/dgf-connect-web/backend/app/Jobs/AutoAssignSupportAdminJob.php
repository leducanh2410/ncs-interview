<?php

namespace App\Jobs;

use App\Actions\Chat\Support\AssignSupportAdminsAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\SupportTypeAdmin;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After 5 minutes with no assigned admin, auto-assign the admins configured for the
 * conversation's support type. If the type has no eligible roster, fall back to
 * chat.assign admins so the conversation is still surfaced to triage owners.
 */
class AutoAssignSupportAdminJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $conversationId) {}

    public function handle(AssignSupportAdminsAction $assign): void
    {
        $conversation = ChatConversation::find($this->conversationId);

        if (!$conversation || $conversation->status === ChatConversationStatus::Closed->value) {
            return;
        }

        $hasActiveAdmin = ChatParticipant::query()
            ->where('conversation_id', $this->conversationId)
            ->where('role', ChatParticipantRole::Admin->value)
            ->where('status', ChatParticipantStatus::Active->value)
            ->exists();

        if ($hasActiveAdmin) {
            return;
        }

        $adminUserIds = $this->resolveAdminUserIds($conversation);

        if (empty($adminUserIds)) {
            return;
        }

        $assign(null, $conversation, ['admin_user_ids' => $adminUserIds]);
    }

    private function resolveAdminUserIds(ChatConversation $conversation): array
    {
        if ($conversation->support_type) {
            $rosteredIds = SupportTypeAdmin::where('support_type', $conversation->support_type)
                ->pluck('user_id')
                ->all();

            if (!empty($rosteredIds)) {
                // Re-validate: exclude anyone who has since lost chat.support/chat.view so stale
                // rosters don't assign users who can no longer handle support chats.
                $validIds = User::query()
                    ->whereIn('id', $rosteredIds)
                    ->where('is_disabled', false)
                    ->whereHasPermission(PermissionName::ChatSupport->value)
                    ->get()
                    ->filter(fn(User $user) => $user->hasPermission(PermissionName::ChatView->value))
                    ->pluck('id')
                    ->all();

                if (!empty($validIds)) {
                    return $validIds;
                }
            }
        }

        return $this->resolveFallbackAdminUserIds();
    }

    private function resolveFallbackAdminUserIds(): array
    {
        return User::query()
            ->where('is_disabled', false)
            ->whereHasPermission(PermissionName::ChatAssign->value)
            ->pluck('id')
            ->all();
    }
}
