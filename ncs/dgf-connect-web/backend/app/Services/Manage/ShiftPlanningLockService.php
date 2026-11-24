<?php

namespace App\Services\Manage;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Whole-workplace planning lock for the Shift Planner (requirements §4.1, Decision 14).
 *
 * Ephemeral + advisory: a Redis-backed cache entry with a TTL, NOT the Cache::lock
 * atomic API (we need holder metadata + TTL extension) and NOT a DB table (TTL
 * auto-cleans). The lock never blocks the shift-detail page or self-registration;
 * finalize re-reads and re-validates live state, so the heartbeat/takeover race is
 * benign and accepted.
 *
 * Renewal is activity-gated by the client: the tab only calls renew() on genuine
 * planning activity, so an idle-but-open tab stops renewing and the TTL frees the
 * workplace automatically.
 */
class ShiftPlanningLockService
{
    public const LOCK_TTL = 300; // seconds

    private const LOCK_PREFIX = 'shift_planning_lock:';

    /** Atomic first-writer-wins; fails if currently held by anyone (incl. self). */
    public function acquire(int $workplaceId, int $userId, string $name): bool
    {
        return Cache::add($this->key($workplaceId), $this->payload($userId, $name), self::LOCK_TTL);
    }

    /** Heartbeat: extend the TTL and bump last activity. Only the owner may renew. */
    public function renew(int $workplaceId, int $userId): bool
    {
        $lock = $this->get($workplaceId);
        if (!$lock || $lock['user_id'] !== $userId) {
            return false;
        }

        Cache::put(
            $this->key($workplaceId),
            $this->payload($userId, $lock['name'], $lock['acquired_at']),
            self::LOCK_TTL
        );

        return true;
    }

    /**
     * Confirmed force-takeover: overwrite the holder unconditionally. The UI only
     * offers this once the previous holder's lock looks stale/expired; the lock is
     * advisory and finalize re-validates, so the takeover race is benign.
     */
    public function takeover(int $workplaceId, int $userId, string $name): void
    {
        Cache::put($this->key($workplaceId), $this->payload($userId, $name), self::LOCK_TTL);
    }

    /** Release the lock. Only the owner may release. */
    public function release(int $workplaceId, int $userId): bool
    {
        $lock = $this->get($workplaceId);
        if (!$lock) {
            return true;
        }
        if ($lock['user_id'] !== $userId) {
            return false;
        }

        Cache::forget($this->key($workplaceId));

        return true;
    }

    /**
     * @return array{user_id:int, name:string, acquired_at:string, last_activity_at:string, ttl:int}|null
     */
    public function get(int $workplaceId): ?array
    {
        $raw = Cache::get($this->key($workplaceId));
        if (!$raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!$decoded) {
            return null;
        }

        $elapsed = now()->timestamp - Carbon::parse($decoded['last_activity_at'])->timestamp;

        return [
            'user_id' => $decoded['user_id'],
            'name' => $decoded['name'],
            'acquired_at' => $decoded['acquired_at'],
            'last_activity_at' => $decoded['last_activity_at'],
            'ttl' => max(0, self::LOCK_TTL - $elapsed),
        ];
    }

    public function isHeldBy(int $workplaceId, int $userId): bool
    {
        $lock = $this->get($workplaceId);

        return $lock !== null && $lock['user_id'] === $userId;
    }

    private function payload(int $userId, string $name, ?string $acquiredAt = null): string
    {
        $now = now()->toIso8601String();

        return json_encode([
            'user_id' => $userId,
            'name' => $name,
            'acquired_at' => $acquiredAt ?? $now,
            'last_activity_at' => $now,
        ]);
    }

    private function key(int $workplaceId): string
    {
        return self::LOCK_PREFIX . $workplaceId;
    }
}
