<?php

namespace App\Actions\Chat\Message;

use App\Actions\ActionResult;
use App\Actions\Chat\Access\ResolveParticipantAccessAction;
use App\Actions\Chat\Keyword\CheckMessageBlockedAction;
use App\Actions\Chat\Keyword\FilterChatBodyAction;
use App\Actions\Chat\Notification\NotifyChatRecipientsAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ErrorCode;
use App\Events\NewChatMessage;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\Image as LaravelImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SendMessageAction
{
    public function __construct(
        private readonly ResolveParticipantAccessAction $resolveAccess,
        private readonly NotifyChatRecipientsAction $notify,
        private readonly CheckMessageBlockedAction $checkBlocked,
        private readonly FilterChatBodyAction $filterBody
    ) {}

    public function __invoke(User $user, ChatConversation $conversation, array $params): ActionResult
    {
        $self = ($this->resolveAccess)($user, $conversation->id);
        if ($self === null) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        /** @var list<UploadedFile> $files */
        $files = $params['files'] ?? [];
        $imageFiles = array_values(array_filter($files, fn(UploadedFile $f) => $this->isImage($f)));
        $otherFiles = array_values(array_filter($files, fn(UploadedFile $f) => !$this->isImage($f)));
        $hasBody = filled($params['body'] ?? null);

        $isGroupChat = !in_array(
            $conversation->type,
            [ChatConversationType::Support->value, ChatConversationType::GuestSupport->value],
            true
        );

        $isPrivileged = in_array(
            $self->role,
            [ChatParticipantRole::Admin->value, ChatParticipantRole::Deputy->value],
            true
        );

        // Employee permissions gate each content kind separately in group chats; admins and
        // deputies are never gated. Support chats follow their own visibility rules.
        if ($isGroupChat && !$isPrivileged) {
            if (
                ($hasBody && !$conversation->employee_can_send_message) ||
                ($imageFiles !== [] && !$conversation->employee_can_send_image) ||
                ($otherFiles !== [] && !$conversation->employee_can_send_file)
            ) {
                return new ActionResult(error: ErrorCode::Forbidden, status: 403);
            }
        }

        // A closed conversation is read-only for employees and support chats. In group chats,
        // admins and deputies may still send messages even when the group is locked.
        if ($conversation->status === ChatConversationStatus::Closed->value) {
            if (!$isGroupChat || !$isPrivileged) {
                return new ActionResult(error: ErrorCode::ChatConversationClosed, status: 422);
            }
        }

        $clientMessageId = $params['client_message_id'] ?? null;

        // Idempotency fast-path: a resent POST returns the existing message; the keyword check is
        // skipped so that retries of a message that was successfully delivered before the keyword
        // was added are not suddenly rejected.
        if ($clientMessageId !== null) {
            $existing = ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('client_message_id', $clientMessageId)
                ->first();
            if ($existing !== null) {
                return new ActionResult(data: $this->buildPayload($existing));
            }
        }

        if ($hasBody) {
            $blockedKeywords = $this->checkBlocked->matches($params['body']);
            if ($blockedKeywords !== []) {
                return new ActionResult(
                    error: ErrorCode::ChatMessageContainsBannedKeyword,
                    status: 422,
                    data: ['keywords' => $blockedKeywords]
                );
            }
        }

        $messageType = $this->resolveMessageType($files, $imageFiles, $otherFiles);

        try {
            $message = DB::transaction(function () use (
                $user,
                $conversation,
                $params,
                $clientMessageId,
                $messageType,
                $files
            ) {
                $message = ChatMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_user_id' => $user->id,
                    'client_message_id' => $clientMessageId,
                    'message_type' => $messageType,
                    'body' => $params['body'] ?? null,
                ]);

                $this->storeAttachments($message, $conversation, $user, $files);

                $conversation
                    ->forceFill([
                        'last_message_id' => $message->id,
                        'last_message_at' => $message->created_at,
                        'last_activity_at' => $message->created_at,
                    ])
                    ->save();

                // Bump unread for every other active participant.
                ChatParticipant::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('status', ChatParticipantStatus::Active->value)
                    ->where('user_id', '!=', $user->id)
                    ->increment('unread_count');

                // The sender has implicitly read their own message.
                ChatParticipant::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $user->id)
                    ->update([
                        'last_read_message_id' => $message->id,
                        'last_read_at' => $message->created_at,
                        'unread_count' => 0,
                    ]);

                return $message;
            });
        } catch (QueryException $e) {
            // Concurrent retry with the same client_message_id raced us — return the winner.
            if ($clientMessageId !== null) {
                $existing = ChatMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('client_message_id', $clientMessageId)
                    ->first();
                if ($existing !== null) {
                    return new ActionResult(data: $this->buildPayload($existing));
                }
            }
            throw $e;
        }

        // Broadcast after commit; queued so the send response stays fast.
        NewChatMessage::dispatch($message);
        $this->notify->forMessage($conversation, $message, $user);

        return new ActionResult(data: $this->buildPayload($message));
    }

    /**
     * Build the send/idempotent-retry response payload: the stored body is masked for display and
     * the mask keywords that were applied are surfaced so the sender can be told which of their
     * words became '***'.
     */
    private function buildPayload(ChatMessage $message): array
    {
        $payload = $message
            ->load(['attachments.attachment', 'sender:id,first_name,last_name,profile_picture,short_name'])
            ->toPayloadArray();

        $payload['masked_keywords'] = $this->filterBody->matchedKeywords($payload['body']);
        $payload['body'] = ($this->filterBody)($payload['body']);

        return $payload;
    }

    /**
     * text when there are no files; image when every file is an image; file when any non-image
     * attachment is present.
     */
    private function resolveMessageType(array $files, array $imageFiles, array $otherFiles): string
    {
        if ($files === []) {
            return ChatMessageType::Text->value;
        }

        return $otherFiles === [] ? ChatMessageType::Image->value : ChatMessageType::File->value;
    }

    /**
     * Persist each uploaded file to the private chat disk and link it to the message in order.
     * Images are scaled down to a max dimension (1200 px) before storage so the download/lightbox
     * endpoint never has to serve a multi-megabyte phone photo at full resolution.
     * Additionally a small thumbnail (see config chat.thumbnails.max_width) is generated and
     * stored on the same private disk; the frontend uses it for lightweight inline previews
     * and fetches the (display-sized) original only on explicit download. Image processing
     * failures are non-fatal — the file is still stored and the message saved with
     * thumbnail_path = null, and the frontend falls back gracefully.
     */
    private function storeAttachments(
        ChatMessage $message,
        ChatConversation $conversation,
        User $user,
        array $files
    ): void {
        if ($files === []) {
            return;
        }

        $disk = config('chat.attachments.disk');
        $directory = "chat/{$conversation->id}";
        $position = 0;

        /** @var UploadedFile $file */
        foreach ($files as $file) {
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();

            $thumbnailPath = null;
            $path = null;
            if ($this->isImage($file)) {
                $image = null;
                try {
                    $image = Image::fromUpload($file);

                    // Cap the stored "original" so large phone photos don't bloat storage and the
                    // bandwidth of every lightbox fetch. A failure (e.g. unsupported format)
                    // falls back to storing the uploaded file untouched.
                    $path = $image->scale(width: 1200)->quality(80)->store($directory, $disk);
                } catch (Throwable) {
                    // Scaling is best-effort; never roll back the message send over it.
                }

                if ($image !== null) {
                    try {
                        $thumbnailPath = $this->storeThumbnail($image, $disk);
                    } catch (Throwable) {
                        // Thumbnail is display-only; a failure must not roll back the message send.
                    }
                }
            }

            $path = $path ?: $file->store($directory, $disk);

            $attachment = ChatAttachment::query()->create([
                'disk' => $disk,
                'path' => $path,
                'thumbnail_path' => $thumbnailPath,
                'thumbnail_disk' => $thumbnailPath ? $disk : null,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size_bytes' => Storage::disk($disk)->size($path),
                'created_by_user_id' => $user->id,
            ]);

            ChatMessageAttachment::query()->create([
                'message_id' => $message->id,
                'attachment_id' => $attachment->id,
                'position' => $position++,
            ]);
        }
    }

    /**
     * Generate a thumbnail (config chat.thumbnails.max_width px wide, JPEG quality 75) and store
     * it on the same private disk as the original. The thumbnail is served through the
     * auth-checked /chat/attachments/{id}/thumbnail route, not exposed directly.
     */
    private function storeThumbnail(LaravelImage $image, string $disk): string
    {
        $maxWidth = (int) config('chat.thumbnails.max_width', 200);
        $path = 'chat/thumbs/' . (string) Str::ulid() . '.jpg';
        $encoded = $image->scale(width: $maxWidth)->toJpeg()->quality(75)->toBytes();
        Storage::disk($disk)->put($path, $encoded);

        return $path;
    }

    private function isImage(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), config('chat.attachments.image_mimes'), true);
    }
}
