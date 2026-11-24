<?php

namespace App\Actions\Chat\Conversation;

use App\Actions\ActionResult;
use App\Actions\Chat\Access\ResolveConversationViewAccessAction;
use App\Actions\Chat\Concerns\ResolvesGlobalAdminUserIds;
use App\Actions\Chat\Keyword\FilterChatBodyAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantStatus;
use App\Enums\ErrorCode;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;

class GetConversationDetailAction
{
    use ResolvesGlobalAdminUserIds;

    public function __construct(
        private readonly ResolveConversationViewAccessAction $resolveAccess,
        private readonly FilterChatBodyAction $filterBody
    ) {}

    /**
     * @param bool $includeReadState Expose every participant's read marker so admin clients can
     *                               draw per-message read receipts. Admin callers only.
     */
    public function __invoke(
        User $user,
        ChatConversation $conversation,
        bool $includeGlobalAdminFlag = false,
        bool $includeReadState = false
    ): ActionResult {
        $access = ($this->resolveAccess)($user, $conversation);
        if ($access === null) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $self = $access->participant;

        $conversation->load([
            'participants' => fn($query) => $query->where('status', ChatParticipantStatus::Active->value),
            'participants.user:id,first_name,last_name,staff_no,profile_picture,short_name',
            'pinnedMessage.sender:id,first_name,last_name,profile_picture,short_name',
            'pinnedMessage.attachments.attachment',
        ]);

        $isGuest = $conversation->type === ChatConversationType::GuestSupport->value;
        if ($isGuest) {
            $conversation->loadMissing('guestSupportSession');
        }

        $pinnedMessage = $conversation->pinnedMessage?->toPayloadArray();
        if ($pinnedMessage !== null && $conversation->pinnedMessage->message_type !== ChatMessageType::System->value) {
            $pinnedMessage['body'] = ($this->filterBody)($pinnedMessage['body']);
        }

        // The Global Admin badge is admin-UI only, so the flag is exposed solely to admin callers
        // and never leaked through the employee-facing conversation detail endpoint.
        $globalAdminMap = $includeGlobalAdminFlag
            ? $this->globalAdminUserIdMap($conversation->participants->pluck('user_id'))
            : [];

        return new ActionResult(
            data: [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'system_key' => $conversation->system_key,
                'status' => $conversation->status,
                'support_type' => $conversation->support_type,
                'title' => $conversation->title,
                // Null on a shift group means its shift was deleted; such a chat can never be reopened.
                'shift_id' => $conversation->shift_id,
                'closed_by_role' => $conversation->closed_by_role,
                'permissions' => [
                    'employee_can_send_message' => $conversation->employee_can_send_message,
                    'employee_can_send_image' => $conversation->employee_can_send_image,
                    'employee_can_send_file' => $conversation->employee_can_send_file,
                    'can_employee_reopen' => $conversation->can_employee_reopen,
                ],
                'self' =>
                    $self === null
                        ? null
                        : [
                            'role' => $self->role,
                            'unread_count' => $self->unread_count,
                            'last_read_message_id' => $self->last_read_message_id,
                            'last_read_at' => optional($self->last_read_at)->toIso8601String(),
                        ],
                'extra_participant_user_ids' => $conversation->extra_participant_user_ids ?? [],
                'last_message_id' => $conversation->last_message_id,
                'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
                'pinned_message' => $pinnedMessage,
                'participants' => $conversation->participants
                    ->map(
                        fn(ChatParticipant $participant) => [
                            'user_id' => $participant->user_id,
                            'name' => $participant->user?->short_name,
                            'staff_no' => $participant->user?->staff_no,
                            'profile_picture' => $participant->user?->profile_picture,
                            'role' => $participant->role,
                            ...$includeGlobalAdminFlag
                                ? ['is_global_admin' => isset($globalAdminMap[$participant->user_id])]
                                : [],
                            ...$includeReadState
                                ? [
                                    'last_read_message_id' => $participant->last_read_message_id,
                                    'last_read_at' => optional($participant->last_read_at)->toIso8601String(),
                                ]
                                : [],
                            'joined_at' => optional($participant->joined_at)->toIso8601String(),
                        ]
                    )
                    ->all(),
                'guest_info' =>
                    $isGuest && $conversation->guestSupportSession
                        ? [
                            'name' => $conversation->guestSupportSession->guest_name,
                            'email' => $conversation->guestSupportSession->guest_email,
                            'device_id' => $conversation->guestSupportSession->device_id,
                            'platform' => $conversation->guestSupportSession->platform,
                        ]
                        : null,
            ]
        );
    }
}
