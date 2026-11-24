<?php

namespace App\Actions\Manage\Announcement;

use App\Enums\Locale;
use App\Models\Announcement;
use App\Rules\NonEmptyHtml;
use Illuminate\Support\Arr;

class SyncAnnouncementTranslationsAction
{
    public function __invoke(Announcement $announcement, array $values): void
    {
        foreach (Locale::getBaseTranslationLocales() as $locale) {
            $title = mb_trim((string) Arr::get($values, "title_{$locale}", ''));
            $content = mb_trim((string) Arr::get($values, "content_{$locale}", ''));

            if ($title === '' || NonEmptyHtml::isBlank($content)) {
                $announcement->translations()->where('lang', $locale)->delete();

                continue;
            }

            $announcement
                ->translations()
                ->updateOrCreate(['lang' => $locale], ['title' => $title, 'content' => $content]);
        }
    }
}
