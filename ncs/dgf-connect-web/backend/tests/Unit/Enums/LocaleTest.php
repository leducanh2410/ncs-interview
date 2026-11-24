<?php

namespace Tests\Unit\Enums;

use App\Enums\Locale;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function testValuesIncludeNepaliAndSinhala()
    {
        $values = Locale::values();

        $this->assertContains('ne', $values);
        $this->assertContains('si', $values);
    }

    public function testGetTranslationLocalesExcludesTheActiveAppLocale()
    {
        // No SetLocale middleware has run, so the active locale is still the app's boot-time
        // default ('vi').
        $translationLocales = Locale::getTranslationLocales();

        $this->assertNotContains('vi', $translationLocales);
        $this->assertContains('en', $translationLocales);
        $this->assertContains('ja', $translationLocales);
        $this->assertContains('ne', $translationLocales);
        $this->assertContains('si', $translationLocales);
    }

    /**
     * Notification-creation flows (CreateNotificationForUser, ApprovePapersAction, ...) write
     * their base title/content with __() under whatever locale is currently active, then use
     * this list to fill in the rest. It must track that active locale — not a fixed one — or a
     * Japanese-speaking admin's Japanese-language base content would end up with no 'vi' row,
     * since 'ja' would already be excluded as "the base locale" while the content was written
     * in Japanese.
     */
    public function testGetTranslationLocalesFollowsTheActiveAppLocaleWhenItChanges()
    {
        App::setLocale('ja');

        $translationLocales = Locale::getTranslationLocales();

        $this->assertNotContains('ja', $translationLocales);
        $this->assertContains('vi', $translationLocales);
        $this->assertContains('en', $translationLocales);
        $this->assertContains('ne', $translationLocales);
        $this->assertContains('si', $translationLocales);
    }

    /**
     * SetLocale switches the active app locale to whichever admin is making the request
     * (their own settings.lang), not to a system-wide default. Unlike getTranslationLocales(),
     * the admin Notification form's contract (StoreRequest / NotificationService) must stay
     * pinned to the fixed system locale regardless, or a Japanese-speaking admin would see
     * title_ja/content_ja silently excluded from the fields their request even accepts.
     */
    public function testGetBaseTranslationLocalesIgnoresTheActiveAppLocale()
    {
        App::setLocale('ja');

        $translationLocales = Locale::getBaseTranslationLocales();

        $this->assertContains('ja', $translationLocales);
        $this->assertNotContains(config('app.base_locale'), $translationLocales);
    }
}
