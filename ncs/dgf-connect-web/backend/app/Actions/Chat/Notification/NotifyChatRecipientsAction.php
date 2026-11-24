<?php

namespace App\Actions\Chat\Notification;

use App\Actions\Chat\Keyword\FilterChatBodyAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantStatus;
use App\Enums\Locale;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Jobs\SendGuestChatFcmJob;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Single chokepoint for chat push notifications. Resolves recipients, builds per-locale title/body
 * (localized at send time, mirroring the poll FCM flow), and dispatches chunked FCM jobs.
 * Push-only — it deliberately does not write Notification / notification-center rows.
 *
 * Callers invoke this AFTER their DB transaction commits, alongside the existing broadcast dispatch.
 */
class NotifyChatRecipientsAction
{
    public function __construct(
        private readonly FilterChatBodyAction $filterBody,
        private readonly DispatchChatMessageFcmJobsAction $dispatchFcmJobs
    ) {}

    /**
     * A new real message (text/image/file) from a sender to the other active participants.
     * For guest_support conversations, also sends FCM to the guest device.
     */
    public function forMessage(ChatConversation $conversation, ChatMessage $message, User $sender): void
    {
        $recipientIds = $this->activeRecipientIds($conversation, $sender->id);

        $filteredBody = ($this->filterBody)((string) $message->body);

        $body = match ($message->message_type) {
            ChatMessageType::Image->value => $this->localize('chat.notification.sent_image'),
            ChatMessageType::File->value => $this->localize('chat.notification.sent_file'),
            default => $this->staticByLocale(Str::limit($filteredBody, 120)),
        };

        $this->dispatch($conversation, $recipientIds, $this->messageTitle($conversation, $sender), $body, [
            'type' => NotificationType::CHAT_MESSAGE->value,
            'conversation_id' => (string) $conversation->id,
            'message_id' => (string) $message->id,
        ]);

        if ($conversation->type === ChatConversationType::GuestSupport->value) {
            $conversation->loadMissing('guestSupportSession');
            $fcmToken = $conversation->guestSupportSession?->fcm_token;
            if ($fcmToken !== null) {
                SendGuestChatFcmJob::dispatch(
                    $fcmToken,
                    $conversation->id,
                    $sender->short_name ?? __('chat.notification.default_title'),
                    Str::limit($filteredBody, 120),
                    [
                        'type' => NotificationType::CHAT_MESSAGE->value,
                        'conversation_id' => (string) $conversation->id,
                        'message_id' => (string) $message->id,
                    ]
                );
            }
        }
    }

    /**
     * An employee opened a support chat — notify the triage (chat-assignment) admins.
     */
    public function forSupportCreated(ChatConversation $conversation, ChatMessage $firstMessage, User $employee): void
    {
        $recipientIds = User::query()
            ->whereHasPermission(PermissionName::ChatAssign->value)
            ->where('id', '!=', $employee->id)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        $filteredBody = ($this->filterBody)((string) $firstMessage->body);

        $this->dispatch(
            $conversation,
            $recipientIds,
            $this->localize('chat.notification.support_title'),
            $this->staticByLocale(Str::limit($filteredBody, 120)),
            [
                'type' => NotificationType::CHAT_SUPPORT_CREATED->value,
                'conversation_id' => (string) $conversation->id,
                'message_id' => (string) $firstMessage->id,
            ]
        );
    }

    /**
     * A guest opened a support chat — notify all CHAT_ASSIGN admins via FCM.
     */
    public function forGuestSupportCreated(ChatConversation $conversation, ChatMessage $firstMessage): void
    {
        $recipientIds = User::query()
            ->whereHasPermission(PermissionName::ChatAssign->value)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        $filteredBody = ($this->filterBody)((string) $firstMessage->body);

        $this->dispatch(
            $conversation,
            $recipientIds,
            $this->localize('chat.notification.support_title'),
            $this->staticByLocale(Str::limit($filteredBody, 120)),
            [
                'type' => NotificationType::CHAT_SUPPORT_CREATED->value,
                'conversation_id' => (string) $conversation->id,
                'message_id' => (string) $firstMessage->id,
            ]
        );
    }

    /**
     * A guest sent a follow-up message — notify assigned admins (or all CHAT_ASSIGN if none).
     */
    public function forGuestMessage(ChatConversation $conversation, ChatMessage $message): void
    {
        $assignedIds = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', ChatParticipantStatus::Active->value)
            ->pluck('user_id')
            ->map(fn($id) => (int) $id)
            ->all();

        $recipientIds =
            $assignedIds !== []
                ? $assignedIds
                : User::query()
                    ->whereHasPermission(PermissionName::ChatAssign->value)
                    ->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->all();

        $filteredBody = ($this->filterBody)((string) $message->body);

        $this->dispatch(
            $conversation,
            $recipientIds,
            $this->localize('chat.notification.support_title'),
            $this->staticByLocale(Str::limit($filteredBody, 120)),
            [
                'type' => NotificationType::CHAT_MESSAGE->value,
                'conversation_id' => (string) $conversation->id,
                'message_id' => (string) $message->id,
            ]
        );
    }

    /**
     * A guest reopened their support conversation — notify all active admin participants (or all CHAT_ASSIGN if none).
     */
    public function forGuestReopened(ChatConversation $conversation, ChatMessage $systemMessage): void
    {
        $recipientIds = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', ChatParticipantStatus::Active->value)
            ->pluck('user_id')
            ->map(fn($id) => (int) $id)
            ->all();

        if (empty($recipientIds)) {
            $recipientIds = User::query()
                ->whereHasPermission(PermissionName::ChatAssign->value)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->all();
        }

        $this->dispatch(
            $conversation,
            $recipientIds,
            $this->conversationTitle($conversation),
            $this->localize('chat.notification.conversation_reopened'),
            [
                'type' => NotificationType::CHAT_STATE_CHANGED->value,
                'conversation_id' => (string) $conversation->id,
                'message_id' => (string) $systemMessage->id,
            ]
        );
    }

    /**
     * An admin was assigned to a support chat — notify active participants except the actor.
     * Automatic assignment has no actor, so every active participant is eligible.
     */
    public function forAdminsAssigned(ChatConversation $conversation, ChatMessage $systemMessage, ?User $actor): void
    {
        $recipientIds = $this->activeRecipientIds($conversation, $actor?->id);

        $this->dispatch(
            $conversation,
            $recipientIds,
            $this->conversationTitle($conversation),
            $this->localize('chat.notification.admin_assigned'),
            [
                'type' => NotificationType::CHAT_ADMIN_ASSIGNED->value,
                'conversation_id' => (string) $conversation->id,
                'message_id' => (string) $systemMessage->id,
            ]
        );
    }

    /**
     * A conversation was closed or reopened — notify the other active participants.
     */
    public function forStateChange(
        ChatConversation $conversation,
        ChatMessage $systemMessage,
        User $actor,
        bool $closed
    ): void {
        $recipientIds = $this->activeRecipientIds($conversation, $actor->id);
        $bodyKey = $closed ? 'chat.notification.conversation_closed' : 'chat.notification.conversation_reopened';

        $this->dispatch(
            $conversation,
            $recipientIds,
            $this->conversationTitle($conversation),
            $this->localize($bodyKey),
            [
                'type' => NotificationType::CHAT_STATE_CHANGED->value,
                'conversation_id' => (string) $conversation->id,
                'message_id' => (string) $systemMessage->id,
            ]
        );
    }

    /**
     * @param  array<int, int>  $recipientIds
     * @param  array<string, string>  $titleByLocale
     * @param  array<string, string>  $bodyByLocale
     * @param  array<string, string>  $data
     */
    private function dispatch(
        ChatConversation $conversation,
        array $recipientIds,
        array $titleByLocale,
        array $bodyByLocale,
        array $data
    ): void {
        if (empty($recipientIds)) {
            return;
        }

        $this->dispatchFcmJobs->execute($recipientIds, $conversation->id, $titleByLocale, $bodyByLocale, $data);
    }

    /**
     * @return array<int, int>
     */
    private function activeRecipientIds(ChatConversation $conversation, ?int $excludeUserId): array
    {
        return ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', ChatParticipantStatus::Active->value)
            ->when($excludeUserId !== null, fn($query) => $query->where('user_id', '!=', $excludeUserId))
            ->pluck('user_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function messageTitle(ChatConversation $conversation, User $sender): array
    {
        if ($conversation->title) {
            return $this->staticByLocale($conversation->title);
        }
        if ($sender->short_name) {
            return $this->staticByLocale($sender->short_name);
        }

        return $this->localize('chat.notification.default_title');
    }

    /**
     * @return array<string, string>
     */
    private function conversationTitle(ChatConversation $conversation): array
    {
        return $conversation->title
            ? $this->staticByLocale($conversation->title)
            : $this->localize('chat.notification.default_title');
    }

    /**
     * Localize a translation key into every supported locale (rendered per recipient at send time).
     *
     * @return array<string, string>
     */
    private function localize(string $key): array
    {
        $byLocale = [];
        foreach (Locale::values() as $locale) {
            $byLocale[$locale] = __($key, [], $locale);
        }

        return $byLocale;
    }

    /**
     * Wrap a non-translatable value (conversation title, sender name, message body) as the same
     * string for every locale so the job's per-locale lookup always resolves.
     *
     * @return array<string, string>
     */
    private function staticByLocale(string $value): array
    {
        $byLocale = [];
        foreach (Locale::values() as $locale) {
            $byLocale[$locale] = $value;
        }

        return $byLocale;
    }
}
