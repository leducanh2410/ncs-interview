<?php

namespace App\Support\Dashboard;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Tag-based cache for the read-only dashboard aggregates.
 *
 * Every entry is tagged with a "view" signature derived from the viewer's
 * resolved workplace scope (so distinct permission scopes never share cached
 * data). All four dashboard widgets for one scope share that single tag, which
 * lets the clear-cache endpoint flush the user's current view in one call.
 * The per-endpoint $params (range, heatmap mode, …) only vary the cache key,
 * not the tag, so clearing a view drops every window/mode under it.
 */
final class DashboardCache
{
    private const CACHE_KEY_VERSION = 'v2';

    public static function ttl(): int
    {
        return (int) config('dashboard.cache_ttl');
    }

    /**
     * @param  array<string, mixed>  $scope   Resolved workplace scope; drives the view tag.
     * @param  array<string, mixed>  $params  Endpoint-specific inputs; vary the key only.
     */
    public static function remember(string $endpoint, array $scope, array $params, Closure $callback): mixed
    {
        $tag = self::viewTag($scope);
        $key = $endpoint . ':' . self::CACHE_KEY_VERSION . ':' . md5(json_encode([$tag, $params]));

        return Cache::tags([$tag])->remember($key, self::ttl(), $callback);
    }

    /**
     * Flush every cached widget for a single view.
     *
     * @param  array<string, mixed>  $scope
     */
    public static function flush(array $scope): void
    {
        Cache::tags([self::viewTag($scope)])->flush();
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private static function viewTag(array $scope): string
    {
        return 'dashboard:view:' . md5(json_encode($scope));
    }
}
