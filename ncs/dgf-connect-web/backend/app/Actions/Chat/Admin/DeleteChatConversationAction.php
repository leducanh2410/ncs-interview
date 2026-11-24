<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
use App\Enums\ChatAuditAction;
use App\Enums\ChatParticipantStatus;
use App\Enums\ErrorCode;
use App\Events\ChatConversationDeleted;
use App\Models\ChatAttachment;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DeleteChatConversationAction
{
    public function __invoke(User $actor, ChatConversation $conversation): ActionResult
    {
        // The chat.delete_conversation permission is enforced by the route middleware. The actor
        // must additionally be an active participant of the conversation being deleted.
        $isMember = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->where('status', ChatParticipantStatus::Active->value)
            ->exists();

        if (!$isMember) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        // The internal admin group is reconciled from manager.auth; hand edits would drift
        // against the next sync, so the roster and the conversation itself are read-only here.
        if ($conversation->isSystem()) {
            return new ActionResult(error: ErrorCode::ChatSystemConversationImmutable, status: 422);
        }

        $conversationId = $conversation->id;
        $messageCount = $conversation->messages()->count();
        $attachments = ChatAttachment::query()
            ->join('chat_message_attachments', 'chat_attachments.id', '=', 'chat_message_attachments.attachment_id')
            ->join('chat_messages', 'chat_message_attachments.message_id', '=', 'chat_messages.id')
            ->where('chat_messages.conversation_id', $conversationId)
            ->distinct()
            ->get(['chat_attachments.id', 'chat_attachments.disk', 'chat_attachments.path']);
        $attachmentIds = $attachments->pluck('id')->unique()->values();

        $deletedAttachmentIds = DB::transaction(function () use (
            $actor,
            $attachmentIds,
            $conversation,
            $conversationId,
            $messageCount
        ) {
            ChatAuditLog::query()->create([
                'conversation_id' => $conversationId,
                'actor_user_id' => $actor->id,
                'action' => ChatAuditAction::ConversationDeleted->value,
                'payload' => [
                    'conversation_id' => $conversationId,
                    'type' => $conversation->type,
                    'title' => $conversation->title,
                    'message_count' => $messageCount,
                    'attachment_ids' => $attachmentIds->all(),
                ],
            ]);

            $conversation->delete();

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

        ChatConversationDeleted::dispatch($conversationId);

        return new ActionResult(data: ['id' => $conversationId]);
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
