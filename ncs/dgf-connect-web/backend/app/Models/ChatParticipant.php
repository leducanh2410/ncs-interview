<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property string $role
 * @property string $status
 * @property string|null $joined_at
 * @property string|null $removed_at
 * @property int|null $removed_by_user_id
 * @property int|null $last_read_message_id
 * @property string|null $last_read_at
 * @property int $unread_count
 * @property ChatConversation $conversation
 * @property User $user
 */
class ChatParticipant extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'unread_count' => 'integer',
        'joined_at' => 'datetime',
        'removed_at' => 'datetime',
        'last_read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
