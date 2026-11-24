<?php

namespace Tests\Unit\Services\User;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\User;
use App\Services\User\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'serialized-array',
            'cache.serializable_classes' => false,
            'cache.stores.serialized-array' => [
                'driver' => 'array',
                'serialize' => true,
            ],
        ]);
        Cache::forgetDriver('serialized-array');
    }

    public function testCachesResponseArraysWithObjectDeserializationDisabled(): void
    {
        $user = User::factory()->create();
        $notification = $this->createSystemNotification();
        $notification->translations()->create([
            'lang' => 'ja',
            'title' => 'Japanese title',
            'content' => 'Japanese content',
        ]);
        $this->actingAs($user, 'api');

        $result = app(NotificationService::class)->getLatestSystemNotifications();
        $cachedValue = Cache::get(NotificationService::SYSTEM_NOTIFICATIONS_CACHE_KEY);

        $this->assertIsArray($cachedValue);
        $this->assertSame($notification->id, $cachedValue[0]['id']);
        $this->assertSame('ja', $cachedValue[0]['translations'][0]['lang']);
        $this->assertArrayNotHasKey('is_read', $cachedValue[0]);
        $this->assertFalse($result->first()['is_read']);
        $this->assertStringNotContainsString('O:', serialize($cachedValue));
    }

    public function testCachedAndUncachedResponsesMatchWhileReadStateRemainsUserSpecific(): void
    {
        $reader = User::factory()->create();
        $otherUser = User::factory()->create();
        $notification = $this->createSystemNotification();
        $notification->readStates()->create([
            'user_id' => $reader->id,
            'read_at' => now(),
        ]);
        $service = app(NotificationService::class);

        $this->actingAs($reader, 'api');
        $uncachedReaderResponse = $service->getLatestSystemNotifications()->all();
        $cachedReaderResponse = $service->getLatestSystemNotifications()->all();

        $this->assertSame($uncachedReaderResponse, $cachedReaderResponse);
        $this->assertTrue($cachedReaderResponse[0]['is_read']);

        $this->actingAs($otherUser, 'api');
        $otherUserResponse = $service->getLatestSystemNotifications()->all();

        $this->assertFalse($otherUserResponse[0]['is_read']);
        $this->assertSame(
            array_diff_key($cachedReaderResponse[0], ['is_read' => true]),
            array_diff_key($otherUserResponse[0], ['is_read' => true])
        );

        Cache::forget(NotificationService::SYSTEM_NOTIFICATIONS_CACHE_KEY);
        $this->actingAs($reader, 'api');

        $this->assertSame($uncachedReaderResponse, $service->getLatestSystemNotifications()->all());
    }

    private function createSystemNotification(): Notification
    {
        return Notification::create([
            'type' => 'normal',
            'title' => 'System title',
            'content' => 'System content',
            'status' => NotificationStatus::SENT,
            'scheduled_at' => now()->subMinute(),
            'processed_at' => now(),
            'data' => ['screen' => 'home'],
        ]);
    }
}
