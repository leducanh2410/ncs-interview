<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a chat message to a stored ChatAttachment while preserving per-message ordering.
 *
 * @property int $id
 * @property int $message_id
 * @property int $attachment_id
 * @property int $position
 * @property ChatMessage $message
 * @property ChatAttachment $attachment
 */
class ChatMessageAttachment extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(ChatAttachment::class, 'attachment_id');
    }
}
