<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int|null $sender_user_id
 * @property int|null $guest_support_session_id
 * @property string|null $client_message_id
 * @property string $message_type
 * @property string|null $body
 * @property array|null $system_payload
 * @property ChatConversation $conversation
 * @property User|null $sender
 * @property \Illuminate\Database\Eloquent\Collection<int, ChatMessageAttachment> $attachments
 */
class ChatMessage extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'system_payload' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatMessageAttachment::class, 'message_id')->orderBy('position');
    }

    public function guestSupportSession(): BelongsTo
    {
        return $this->belongsTo(GuestSupportSession::class, 'guest_support_session_id');
    }

    /**
     * Shared shape used by the message-list API, the send-message response, and the
     * NewChatMessage broadcast payload so realtime and REST stay consistent.
     */
    public function toPayloadArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_user_id' => $this->sender_user_id,
            'sender_name' =>
                ($this->relationLoaded('sender') ? $this->sender?->short_name : null) ??
                ($this->relationLoaded('guestSupportSession') ? $this->guestSupportSession?->guest_name : null),
            'sender_profile_picture' => $this->relationLoaded('sender') ? $this->sender?->profile_picture : null,
            'client_message_id' => $this->client_message_id,
            'message_type' => $this->message_type,
            'body' => $this->body,
            'system_payload' => $this->system_payload,
            'attachments' => $this->relationLoaded('attachments')
                ? $this->attachments
                    ->map(
                        fn(ChatMessageAttachment $a) => [
                            'id' => $a->id,
                            'attachment_id' => $a->attachment_id,
                            'type' => self::attachmentType($a->attachment?->mime_type),
                            'url' => route('chat.attachments.download', $a->attachment_id),
                            'thumbnail_url' =>
                                $a->attachment?->thumbnail_path !== null
                                    ? route('chat.attachments.thumbnail', $a->attachment_id)
                                    : null,
                            'filename' => $a->attachment?->original_name,
                            'mime_type' => $a->attachment?->mime_type,
                            'size_bytes' => $a->attachment?->size_bytes,
                            'position' => $a->position,
                        ]
                    )
                    ->all()
                : [],
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * Render-time category for an attachment, derived from its mime type: 'image' for the configured
     * image mimes, 'file' otherwise.
     */
    private static function attachmentType(?string $mimeType): string
    {
        return in_array($mimeType, config('chat.attachments.image_mimes'), true) ? 'image' : 'file';
    }
}
