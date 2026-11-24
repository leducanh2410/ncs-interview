<?php

namespace App\Actions\Chat\Broadcast;

use App\Events\ChatConversationCreated;
use Illuminate\Support\Arr;

/**
 * Fans the "conversation created" broadcast out to every recipient.
 *
 * Two safeguards against large groups overwhelming the shared queue worker / broadcaster:
 *
 *  - The roster is stripped from the broadcast payload (replaced by participants_count) so each
 *    message stays small. Clients refetch the conversation on receipt. The caller keeps the full
 *    payload for its own HTTP response; only the broadcast is slimmed.
 *  - Recipients are batched: one ChatConversationCreated event carries up to channel_chunk_size
 *    private channels in a single trigger, so N recipients produce ceil(N / chunk) broadcast jobs
 *    instead of N.
 */
class DispatchConversationCreatedAction
{
    /**
     * @param array<int> $userIds Recipient user ids (each notified on their private user-{id} channel).
     * @param array<string, mixed> $conversationPayload Full conversation payload; the roster is dropped here.
     */
    public function __invoke(array $userIds, array $conversationPayload): void
    {
        $userIds = collect($userIds)->map(fn($id) => (int) $id)->unique()->values()->all();

        if ($userIds === []) {
            return;
        }

        $broadcastPayload = $this->toBroadcastPayload($conversationPayload);
        $chunkSize = max(1, (int) config('chat.broadcast.channel_chunk_size', 100));

        foreach (array_chunk($userIds, $chunkSize) as $chunk) {
            ChatConversationCreated::dispatch($chunk, $broadcastPayload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function toBroadcastPayload(array $payload): array
    {
        return Arr::except($payload, ['participants']) + [
            'participants_count' => count($payload['participants'] ?? []),
        ];
    }
}
