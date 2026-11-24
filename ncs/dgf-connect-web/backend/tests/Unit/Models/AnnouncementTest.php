<?php

namespace Tests\Unit\Models;

use App\Enums\AnnouncementState;
use App\Models\Announcement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testStateIsInactiveWhenFlagIsOff(): void
    {
        $announcement = Announcement::factory()->inactive()->create();

        $this->assertSame(AnnouncementState::Inactive->value, $announcement->state);
    }

    public function testStateIsScheduledBeforeStart(): void
    {
        $announcement = Announcement::factory()->scheduled()->create();

        $this->assertSame(AnnouncementState::Scheduled->value, $announcement->state);
    }

    public function testStateIsActiveInsideWindow(): void
    {
        $announcement = Announcement::factory()->create();

        $this->assertSame(AnnouncementState::Active->value, $announcement->state);
    }

    public function testStateIsExpiredAfterEnd(): void
    {
        $announcement = Announcement::factory()->expired()->create();

        $this->assertSame(AnnouncementState::Expired->value, $announcement->state);
    }

    public function testStateIsActiveExactlyAtStartAndExpiredExactlyAtEnd(): void
    {
        $start = Carbon::parse('2026-10-01 08:00:00');
        $end = Carbon::parse('2026-10-03 18:00:00');
        $announcement = Announcement::factory()->create(['start_at' => $start, 'end_at' => $end]);

        $this->assertSame(AnnouncementState::Active, $announcement->stateAt($start));
        $this->assertSame(AnnouncementState::Scheduled, $announcement->stateAt($start->copy()->subSecond()));
        $this->assertSame(AnnouncementState::Expired, $announcement->stateAt($end));
        $this->assertSame(AnnouncementState::Active, $announcement->stateAt($end->copy()->subSecond()));
    }

    public function testValidAtScopeKeepsOnlyActiveAnnouncementsInsideTheirWindow(): void
    {
        Carbon::setTestNow('2026-10-02 12:00:00');

        $valid = Announcement::factory()->create();
        Announcement::factory()->inactive()->create();
        Announcement::factory()->scheduled()->create();
        Announcement::factory()->expired()->create();
        $deleted = Announcement::factory()->create();
        $deleted->delete();

        $ids = Announcement::query()->validAt(now())->pluck('id')->all();

        $this->assertSame([$valid->id], $ids);
    }

    public function testImageUrlIsNullWithoutImage(): void
    {
        $announcement = Announcement::factory()->create();

        $this->assertNull($announcement->image_url);
    }
}
