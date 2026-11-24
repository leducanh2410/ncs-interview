<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A stored chat file (image or document). Served only through the access-checked download route, so
 * there is no public url accessor — see ChatMessage::toPayloadArray() / ChatAttachmentController.
 *
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string|null $thumbnail_path
 * @property string|null $thumbnail_disk
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $created_by_user_id
 * @property User|null $creator
 */
class ChatAttachment extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The single link row tying this stored file to its message (one attachment is created per
     * message), used to resolve the owning conversation for download authorization.
     */
    public function messageAttachment(): HasOne
    {
        return $this->hasOne(ChatMessageAttachment::class, 'attachment_id');
    }
}
