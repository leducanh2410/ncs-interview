<?php

namespace App\Services\Admin\PdfTemplate;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PdfTemplateLockService
{
    private const LOCK_PREFIX = 'pdf_template:lock:';

    private const LOCK_TTL = 1800;

    public function acquire(int $templateId, int $userId, string $reason, string $userName): bool
    {
        $key = $this->getLockKey($templateId);
        $lockData = json_encode([
            'user_id' => $userId,
            'reason' => $reason,
            'user_name' => $userName,
            'locked_at' => now()->toIso8601String(),
        ]);

        return Cache::add($key, $lockData, self::LOCK_TTL);
    }

    public function release(int $templateId, int $userId): bool
    {
        $lockInfo = $this->get($templateId);

        if (!$lockInfo) {
            return true;
        }

        if ($lockInfo['user_id'] !== $userId) {
            return false;
        }

        Cache::forget($this->getLockKey($templateId));

        return true;
    }

    public function refresh(int $templateId, int $userId): bool
    {
        $lockInfo = $this->get($templateId);

        if (!$lockInfo || $lockInfo['user_id'] !== $userId) {
            return false;
        }

        $lockData = json_encode([
            'user_id' => $lockInfo['user_id'],
            'reason' => $lockInfo['reason'],
            'user_name' => $lockInfo['user_name'],
            'locked_at' => now()->toIso8601String(),
        ]);
        Cache::put($this->getLockKey($templateId), $lockData, self::LOCK_TTL);

        return true;
    }

    public function get(int $templateId): ?array
    {
        $key = $this->getLockKey($templateId);
        $lockData = Cache::get($key);

        if (!$lockData) {
            return null;
        }

        $decoded = json_decode($lockData, true);

        if (!$decoded) {
            return null;
        }

        $elapsed = now()->timestamp - Carbon::parse($decoded['locked_at'])->timestamp;
        $ttl = max(0, self::LOCK_TTL - $elapsed);

        return [
            'user_id' => $decoded['user_id'],
            'reason' => $decoded['reason'],
            'user_name' => $decoded['user_name'],
            'locked_at' => $decoded['locked_at'],
            'ttl' => $ttl,
        ];
    }

    public function isLocked(int $templateId): bool
    {
        return $this->get($templateId) !== null;
    }

    public function isLockedBy(int $templateId, int $userId): bool
    {
        $lockInfo = $this->get($templateId);

        return $lockInfo !== null && $lockInfo['user_id'] === $userId;
    }

    private function getLockKey(int $templateId): string
    {
        return self::LOCK_PREFIX . $templateId;
    }
}
