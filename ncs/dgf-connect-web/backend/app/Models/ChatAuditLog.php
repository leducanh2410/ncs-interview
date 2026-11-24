<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores immutable audit rows for chat operations.
 *
 * The foreign-key `conversation_id` is nullable so delete logs can survive hard-deleting a
 * conversation; payloads keep the original conversation id for historical lookup.
 *
 * @property int $id
 * @property int|null $conversation_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property array|null $payload
 * @property ChatConversation|null $conversation
 * @property User|null $actor
 */
class ChatAuditLog extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public function save(array $options = []): bool
    {
        $this->mergeConversationIdIntoPayload();

        return parent::save($options);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    private function mergeConversationIdIntoPayload(): void
    {
        if ($this->conversation_id === null) {
            return;
        }

        $payload = $this->payload ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }

        if (!array_key_exists('conversation_id', $payload)) {
            $payload['conversation_id'] = $this->conversation_id;
            $this->payload = $payload;
        }
    }
}
