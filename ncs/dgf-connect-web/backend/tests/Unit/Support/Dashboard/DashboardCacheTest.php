<?php

namespace Tests\Unit\Support\Dashboard;

use App\Support\Dashboard\DashboardCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::forgetDriver('array');
    }

    public function testRememberIgnoresLegacyUnversionedCacheEntries(): void
    {
        $scope = [
            'demand' => [1],
            'reliability' => [1],
            'requested' => null,
        ];
        $params = ['start' => '2026-07-01', 'end' => '2026-07-16'];
        $tag = 'dashboard:view:' . md5(json_encode($scope));
        $legacyKey = 'kpis:' . md5(json_encode([$tag, $params]));

        Cache::tags([$tag])->put($legacyKey, ['source' => 'legacy'], DashboardCache::ttl());

        $callbackCalls = 0;
        $callback = static function () use (&$callbackCalls): array {
            $callbackCalls++;

            return ['source' => 'current'];
        };

        $this->assertSame(['source' => 'current'], DashboardCache::remember('kpis', $scope, $params, $callback));
        $this->assertSame(['source' => 'current'], DashboardCache::remember('kpis', $scope, $params, $callback));
        $this->assertSame(1, $callbackCalls);
    }
}
