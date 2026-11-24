<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
use App\Enums\ChatAuditAction;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantStatus;
use App\Enums\ErrorCode;
use App\Events\ChatMessageDeleted;
use App\Models\ChatAttachment;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DeleteChatMessageAction
{
    public function __invoke(User $actor, ChatConversation $conversation, int $messageId): ActionResult
    {
        // The chat.delete_message permission is enforced by the route middleware. The actor must
        // additionally be an active participant of the conversation they are deleting from.
        $isMember = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->where('status', ChatParticipantStatus::Active->value)
            ->exists();

        if (!$isMember) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $message = ChatMessage::query()->where('id', $messageId)->where('conversation_id', $conversation->id)->first();

        if ($message === null) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        if ($message->message_type === ChatMessageType::System->value) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        $attachments = ChatAttachment::query()
            ->join('chat_message_attachments', 'chat_attachments.id', '=', 'chat_message_attachments.attachment_id')
            ->where('chat_message_attachments.message_id', $messageId)
            ->get(['chat_attachments.id', 'chat_attachments.disk', 'chat_attachments.path']);
        $attachmentIds = $attachments->pluck('id')->unique()->values();
        $conversationId = $conversation->id;

        $deletedAttachmentIds = DB::transaction(function () use (
            $actor,
            $attachmentIds,
            $conversation,
            $conversationId,
            $message
        ) {
            ChatAuditLog::query()->create([
                'conversation_id' => $conversationId,
                'actor_user_id' => $actor->id,
                'action' => ChatAuditAction::MessageDeleted->value,
                'payload' => [
                    'conversation_id' => $conversationId,
                    'message_id' => $message->id,
                    'sender_user_id' => $message->sender_user_id,
                    'message_type' => $message->message_type,
                    'body_preview' => $message->body === null ? null : Str::limit($message->body, 200),
                    'attachment_ids' => $attachmentIds->all(),
                ],
            ]);

            $message->delete();

            $this->reconcileUnreadCounts($conversationId);

            $conversationUpdates = [];

            // Reset denormalized last-message pointer when the deleted message was the last one.
            if ($conversation->last_message_id === $message->id) {
                $previous = ChatMessage::query()->where('conversation_id', $conversationId)->latest('id')->first();

                $conversationUpdates['last_message_id'] = $previous?->id;
                $conversationUpdates['last_message_at'] = $previous?->created_at;
            }

            // Reset denormalized last-activity pointer to the latest remaining non-system message.
            $latestActivity = ChatMessage::query()
                ->where('conversation_id', $conversationId)
                ->where('message_type', '!=', ChatMessageType::System->value)
                ->latest('id')
                ->first();

            $conversationUpdates['last_activity_at'] = $latestActivity?->created_at;

            // Reset denormalized pinned-message pointer when the deleted message was pinned.
            if ($conversation->pinned_message_id === $message->id) {
                $conversationUpdates['pinned_message_id'] = null;
                $conversationUpdates['pinned_at'] = null;
                $conversationUpdates['pinned_by_user_id'] = null;
            }

            if ($conversationUpdates !== []) {
                $conversation->forceFill($conversationUpdates)->save();
            }

            if ($attachmentIds->isEmpty()) {
                return collect();
            }

            $deletedAttachmentIds = ChatAttachment::query()
                ->whereIn('id', $attachmentIds)
                ->whereNotExists(function ($query) {
                    $query
                        ->selectRaw('1')
                        ->from('chat_message_attachments')
                        ->whereColumn('chat_message_attachments.attachment_id', 'chat_attachments.id');
                })
                ->pluck('id');

            if ($deletedAttachmentIds->isNotEmpty()) {
                ChatAttachment::query()->whereIn('id', $deletedAttachmentIds)->delete();
            }

            return $deletedAttachmentIds;
        });

        $this->deleteAttachmentFiles($attachments->whereIn('id', $deletedAttachmentIds->all()));

        ChatMessageDeleted::dispatch($message->id, $conversationId);

        return new ActionResult(data: ['id' => $message->id]);
    }

    private function reconcileUnreadCounts(int $conversationId): void
    {
        $unreadCountSubquery = DB::table('chat_messages')
            ->selectRaw('COUNT(*)')
            ->whereColumn('chat_messages.conversation_id', 'chat_participants.conversation_id')
            ->whereColumn('chat_messages.id', '>', DB::raw('COALESCE(chat_participants.last_read_message_id, 0)'))
            ->where('chat_messages.message_type', '!=', ChatMessageType::System->value)
            ->where(function ($query): void {
                $query
                    ->whereNull('chat_participants.joined_at')
                    ->orWhereColumn('chat_messages.created_at', '>=', 'chat_participants.joined_at');
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('chat_messages.sender_user_id')
                    ->orWhereColumn('chat_messages.sender_user_id', '!=', 'chat_participants.user_id');
            });

        ChatParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('status', ChatParticipantStatus::Active->value)
            ->update(['unread_count' => $unreadCountSubquery]);
    }

    private function deleteAttachmentFiles(iterable $attachments): void
    {
        foreach ($attachments as $attachment) {
            try {
                Storage::disk($attachment->disk)->delete($attachment->path);
            } catch (Throwable) {
                // Best-effort: proceed even if file is already gone or disk is unavailable.
            }
        }
    }
}
