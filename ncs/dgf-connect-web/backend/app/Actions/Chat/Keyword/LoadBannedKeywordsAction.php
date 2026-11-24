<?php

namespace App\Actions\Chat\Keyword;

use App\Enums\ChatBannedKeywordAction;
use App\Models\ChatBannedKeyword;
use Illuminate\Support\Facades\Cache;

class LoadBannedKeywordsAction
{
    // v2: cache now stores locale alongside each keyword entry.
    private const CACHE_KEY = 'chat:banned_keywords_v2';

    private const CACHE_TTL = 300;

    /**
     * Returns all active keywords grouped by action.
     *
     * `locale` is admin-facing metadata only and is intentionally NOT used to filter at runtime:
     * every active keyword is applied uniformly so masking and blocking stay consistent across
     * channels — history, send response, conversation lists, notifications and broadcasts (see
     * BannedKeywordMessageTest). The column is still carried in the cache so the policy can be
     * revisited later without another cache-shape change.
     *
     * Result shape: ['block' => string[], 'mask' => string[]]
     */
    public function __invoke(): array
    {
        $all = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $rows = ChatBannedKeyword::query()
                ->where('is_active', true)
                ->get(['keyword', 'action', 'locale']);

            $block = [];
            $mask = [];

            foreach ($rows as $row) {
                $entry = ['keyword' => $row->keyword, 'locale' => $row->locale];
                if ($row->action === ChatBannedKeywordAction::Block->value) {
                    $block[] = $entry;
                } else {
                    $mask[] = $entry;
                }
            }

            return ['block' => $block, 'mask' => $mask];
        });

        return [
            'block' => array_column($all['block'], 'keyword'),
            'mask' => array_column($all['mask'], 'keyword'),
        ];
    }

    public static function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
