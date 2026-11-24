<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks which conversation a user is actively viewing so push can be suppressed for that user
 * while they are looking at the same conversation. This is a short-lived, best-effort heartbeat
 * (Flutter pings on enter and periodically while active) — never guaranteed-delivery logic.
 *
 * Backed by the configured cache store (Redis in production) for cheap TTL semantics.
 */
class ChatPresenceService
{
    public function markActive(int $userId, int $conversationId): void
    {
        $ttl = (int) config('chat.notifications.active_conversation_ttl', 45);
        $this->store()->put($this->key($userId), $conversationId, $ttl);
    }

    public function clearActive(int $userId): void
    {
        $this->store()->forget($this->key($userId));
    }

    public function activeConversationId(int $userId): ?int
    {
        $value = $this->store()->get($this->key($userId));

        return $value === null ? null : (int) $value;
    }

    public function isViewing(int $userId, int $conversationId): bool
    {
        return $this->activeConversationId($userId) === $conversationId;
    }

    private function key(int $userId): string
    {
        return "chat:active_conversation:{$userId}";
    }

    private function store()
    {
        return Cache::store(config('chat.notifications.presence_store', 'redis'));
    }
}
