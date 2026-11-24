<?php

namespace App\Actions\Chat\Lifecycle;

use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DeleteClosedSupportChatsAction
{
    public function __invoke(): void
    {
        $retentionDays = config('chat.support.retention_days');

        if ($retentionDays <= 0) {
            return;
        }

        $cutoff = now()->subDays($retentionDays);
        $totalDeleted = 0;
        $totalSkipped = 0;

        ChatConversation::query()
            ->whereIn('type', [ChatConversationType::Support->value, ChatConversationType::GuestSupport->value])
            ->where('status', ChatConversationStatus::Closed->value)
            ->whereNotNull('closed_at')
            ->where('closed_at', '<=', $cutoff)
            ->chunkById(50, function ($conversations) use (&$totalDeleted, &$totalSkipped) {
                $conversationIds = $conversations->pluck('id');

                $attachments = ChatAttachment::query()
                    ->join(
                        'chat_message_attachments',
                        'chat_attachments.id',
                        '=',
                        'chat_message_attachments.attachment_id'
                    )
                    ->join('chat_messages', 'chat_message_attachments.message_id', '=', 'chat_messages.id')
                    ->whereIn('chat_messages.conversation_id', $conversationIds)
                    ->distinct()
                    ->get([
                        'chat_attachments.id',
                        'chat_attachments.disk',
                        'chat_attachments.path',
                        'chat_messages.conversation_id',
                    ]);

                // Group by conversation so success/failure is assessed per conversation.
                // Files are deleted before the DB transaction so that conversations where
                // file deletion fails can be skipped and retried on the next daily run.
                // Trade-off: if the DB transaction fails after files are deleted, attachment
                // rows will point to missing files until the next run (~1 day). This
                // self-heals because Storage::delete returns true for already-missing files,
                // so the next run will delete the DB records successfully.
                //
                // For partial failures (some files deleted, some not): all attachment records
                // for the conversation are kept intact until every file can be removed. On the
                // next run the already-gone files return true from Storage::delete (idempotent),
                // so the conversation is retried cleanly with no data loss or degradation.
                $attachmentsByConversation = $attachments->groupBy('conversation_id');

                $failedConversationIds = collect();

                foreach ($attachmentsByConversation as $convId => $convAttachments) {
                    $convFailed = false;

                    foreach ($convAttachments as $attachment) {
                        try {
                            $deleted = Storage::disk($attachment->disk)->delete($attachment->path);
                        } catch (Throwable $e) {
                            $deleted = false;
                            Log::warning(
                                "DeleteClosedSupportChats: failed to delete {$attachment->path}: {$e->getMessage()}"
                            );
                        }

                        if (!$deleted) {
                            $convFailed = true;
                            Log::warning(
                                "DeleteClosedSupportChats: failed to delete {$attachment->path} (Storage::delete returned false)"
                            );
                        }
                    }

                    if ($convFailed) {
                        $failedConversationIds->push($convId);
                    }
                }

                $cleanConversationIds = $conversationIds->diff($failedConversationIds)->values();

                $cleanAttachmentIds = $attachments
                    ->whereNotIn('conversation_id', $failedConversationIds->all())
                    ->pluck('id')
                    ->unique()
                    ->values();

                if ($cleanAttachmentIds->isNotEmpty() || $cleanConversationIds->isNotEmpty()) {
                    DB::transaction(function () use ($cleanConversationIds, $cleanAttachmentIds) {
                        if ($cleanAttachmentIds->isNotEmpty()) {
                            ChatAttachment::query()->whereIn('id', $cleanAttachmentIds)->delete();
                        }
                        if ($cleanConversationIds->isNotEmpty()) {
                            ChatConversation::query()->whereIn('id', $cleanConversationIds)->delete();
                        }
                    });
                }

                $totalDeleted += count($cleanConversationIds);
                $totalSkipped += count($failedConversationIds);
            });

        if ($totalDeleted > 0 || $totalSkipped > 0) {
            $message = "DeleteClosedSupportChats: deleted {$totalDeleted} conversations, skipped {$totalSkipped} due to file errors (retention: {$retentionDays} days)";
            // Raise to warning when conversations are stuck so on-call alerts can fire.
            // Skipped conversations are retried each day; if the count never reaches zero,
            // manual intervention is required to remove the unremovable files.
            if ($totalSkipped > 0) {
                Log::warning($message);
            } else {
                Log::info($message);
            }
        }
    }
}
