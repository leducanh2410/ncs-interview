<?php

namespace Tests\Unit\Actions\Manage\Announcement;

use App\Actions\Manage\Announcement\SyncAnnouncementTranslationsAction;
use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncAnnouncementTranslationsActionTest extends TestCase
{
    use RefreshDatabase;

    private SyncAnnouncementTranslationsAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new SyncAnnouncementTranslationsAction();
    }

    public function testCreatesTranslationsForFilledLocalesOnly(): void
    {
        $announcement = Announcement::factory()->create();

        ($this->action)($announcement, [
            'title_ja' => 'タイトル',
            'content_ja' => '<p>内容</p>',
            'title_en' => '',
            'content_en' => '',
        ]);

        $translations = $announcement->translations()->pluck('title', 'lang')->all();

        $this->assertSame(['ja' => 'タイトル'], $translations);
    }

    public function testUpdatesExistingTranslationAndDeletesClearedOne(): void
    {
        $announcement = Announcement::factory()->create();
        $announcement->translations()->create(['lang' => 'ja', 'title' => 'old', 'content' => '<p>old</p>']);
        $announcement->translations()->create(['lang' => 'en', 'title' => 'old en', 'content' => '<p>old</p>']);

        ($this->action)($announcement, [
            'title_ja' => 'new',
            'content_ja' => '<p>new</p>',
            'title_en' => 'kept title only',
        ]);

        $translations = $announcement->translations()->pluck('content', 'lang')->all();

        $this->assertSame(['ja' => '<p>new</p>'], $translations);
    }

    public function testIgnoresBaseLocaleFields(): void
    {
        $announcement = Announcement::factory()->create();

        ($this->action)($announcement, ['title_vi' => 'x', 'content_vi' => '<p>x</p>']);

        $this->assertSame(0, $announcement->translations()->count());
    }
}
