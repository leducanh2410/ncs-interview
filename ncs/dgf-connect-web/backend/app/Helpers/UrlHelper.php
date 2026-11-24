<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;

class UrlHelper
{
    /**
     * @param string|null $disk The disk the file actually lives on. Defaults to the app's default
     *                          disk so existing single-disk callers are unaffected, but callers
     *                          that persist a disk per file (e.g. attachment rows) must pass it —
     *                          otherwise a later change to the default disk breaks old links.
     */
    public static function getStorageUrl(string $path, int $expirationMinutes = 15, ?string $disk = null): string
    {
        try {
            return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes($expirationMinutes));
        } catch (\RuntimeException) {
            return Storage::disk($disk)->url($path);
        }
    }

    public static function getCdnBaseUrl(): string
    {
        return config('app.cdn_base_url');
    }

    public static function getCdnUrl(string $path): string
    {
        $baseUrl = mb_rtrim(self::getCdnBaseUrl(), '/');

        return $baseUrl . '/' . mb_ltrim($path, '/');
    }
}
