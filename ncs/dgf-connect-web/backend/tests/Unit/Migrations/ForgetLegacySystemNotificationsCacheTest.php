<?php

namespace Tests\Unit\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ForgetLegacySystemNotificationsCacheTest extends TestCase
{
    private function migration(): Migration
    {
        return require database_path('migrations/2026_07_16_000001_forget_legacy_system_notifications_cache.php');
    }

    public function testForgetsOnlyLegacySystemNotificationsCacheEntry(): void
    {
        Cache::forever('system_notifications', ['legacy']);
        Cache::forever('system_notifications:v2', ['current']);

        $this->migration()->up();

        $this->assertFalse(Cache::has('system_notifications'));
        $this->assertSame(['current'], Cache::get('system_notifications:v2'));

        Cache::forget('system_notifications:v2');
    }
}
