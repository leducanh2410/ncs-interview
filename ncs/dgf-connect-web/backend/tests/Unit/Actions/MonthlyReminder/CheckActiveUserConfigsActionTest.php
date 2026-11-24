<?php

namespace Tests\Unit\Actions\MonthlyReminder;

use App\Actions\MonthlyReminder\CheckActiveUserConfigsAction;
use App\Models\User;
use App\Models\UserConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckActiveUserConfigsActionTest extends TestCase
{
    use RefreshDatabase;

    private CheckActiveUserConfigsAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CheckActiveUserConfigsAction();
    }

    public function testReturnsEmptyWhenNoUserConfigsExist(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $result = ($this->action)();

        $this->assertEmpty($result);

        Carbon::setTestNow();
    }

    public function testReturnsUserWithBothConfigsActiveNoDates(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $user = User::factory()->create(['staff_no' => 'S001']);
        UserConfig::create([
            'user_id' => $user->id,
            'max_shift_per_day' => 3,
            'max_hour_per_week' => 40,
        ]);

        $result = ($this->action)();

        $this->assertCount(1, $result);
        $this->assertEquals($user->id, $result->first()['user']->id);
        $this->assertEquals(['max_shift_per_day', 'max_hour_per_week'], $result->first()['active_configs']);

        Carbon::setTestNow();
    }

    public function testReturnsUserWithOnlyShiftPerDayActive(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $user = User::factory()->create(['staff_no' => 'S002']);
        UserConfig::create([
            'user_id' => $user->id,
            'max_shift_per_day' => 3,
            'max_hour_per_week' => 40,
            'max_hour_per_week_expiry_date' => '2026-04-15',
        ]);

        $result = ($this->action)();

        $this->assertCount(1, $result);
        $this->assertEquals(['max_shift_per_day'], $result->first()['active_configs']);

        Carbon::setTestNow();
    }

    public function testReturnsUserWithOnlyHourPerWeekActive(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $user = User::factory()->create(['staff_no' => 'S003']);
        UserConfig::create([
            'user_id' => $user->id,
            'max_shift_per_day' => 3,
            'max_shift_per_day_expiry_date' => '2026-04-15',
            'max_hour_per_week' => 35,
        ]);

        $result = ($this->action)();

        $this->assertCount(1, $result);
        $this->assertEquals(['max_hour_per_week'], $result->first()['active_configs']);

        Carbon::setTestNow();
    }

    public function testExcludesExpiredConfig(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $user = User::factory()->create(['staff_no' => 'S004']);
        UserConfig::create([
            'user_id' => $user->id,
            'max_shift_per_day' => 3,
            'max_shift_per_day_expiry_date' => '2026-04-30',
            'max_hour_per_week' => 40,
            'max_hour_per_week_expiry_date' => '2026-04-15',
        ]);

        $result = ($this->action)();

        $this->assertEmpty($result);

        Carbon::setTestNow();
    }

    public function testIncludesConfigWithStartDateAndNoExpiry(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $user = User::factory()->create(['staff_no' => 'S005']);
        UserConfig::create([
            'user_id' => $user->id,
            'max_shift_per_day' => 3,
            'max_shift_per_day_start_date' => '2026-04-01',
            'max_hour_per_week' => 40,
            'max_hour_per_week_start_date' => '2026-01-01',
        ]);

        $result = ($this->action)();

        $this->assertCount(1, $result);
        $this->assertEquals(['max_shift_per_day', 'max_hour_per_week'], $result->first()['active_configs']);

        Carbon::setTestNow();
    }

    public function testExcludesConfigWithStartDateInFuture(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $user = User::factory()->create(['staff_no' => 'S006']);
        UserConfig::create([
            'user_id' => $user->id,
            'max_shift_per_day' => 3,
            'max_shift_per_day_start_date' => '2026-06-01',
            'max_hour_per_week' => 40,
            'max_hour_per_week_start_date' => '2026-05-01',
        ]);

        $result = ($this->action)();

        $this->assertCount(1, $result);
        $this->assertEquals(['max_hour_per_week'], $result->first()['active_configs']);

        Carbon::setTestNow();
    }

    public function testIncludesConfigWithinDateRange(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $user = User::factory()->create(['staff_no' => 'S007']);
        UserConfig::create([
            'user_id' => $user->id,
            'max_shift_per_day' => 3,
            'max_shift_per_day_start_date' => '2026-04-01',
            'max_shift_per_day_expiry_date' => '2026-06-30',
            'max_hour_per_week' => 40,
            'max_hour_per_week_start_date' => '2026-05-01',
            'max_hour_per_week_expiry_date' => '2026-12-31',
        ]);

        $result = ($this->action)();

        $this->assertCount(1, $result);
        $this->assertEquals(['max_shift_per_day', 'max_hour_per_week'], $result->first()['active_configs']);

        Carbon::setTestNow();
    }

    public function testIncludesConfigWhenExpiryEqualsFirstOfMonth(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $user = User::factory()->create(['staff_no' => 'S008']);
        UserConfig::create([
            'user_id' => $user->id,
            'max_shift_per_day' => 3,
            'max_shift_per_day_expiry_date' => '2026-05-01',
            'max_hour_per_week' => 40,
            'max_hour_per_week_expiry_date' => '2026-05-01',
        ]);

        $result = ($this->action)();

        $this->assertCount(1, $result);
        $this->assertEquals(['max_shift_per_day', 'max_hour_per_week'], $result->first()['active_configs']);

        Carbon::setTestNow();
    }

    public function testMultipleUsersWithMixedActiveConfigs(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $user1 = User::factory()->create(['staff_no' => 'S009']);
        UserConfig::create([
            'user_id' => $user1->id,
            'max_shift_per_day' => 3,
            'max_hour_per_week' => 40,
        ]);

        $user2 = User::factory()->create(['staff_no' => 'S010']);
        UserConfig::create([
            'user_id' => $user2->id,
            'max_shift_per_day' => 2,
            'max_shift_per_day_expiry_date' => '2026-03-31',
            'max_hour_per_week' => 35,
            'max_hour_per_week_expiry_date' => '2026-12-31',
        ]);

        $user3 = User::factory()->create(['staff_no' => 'S011']);
        UserConfig::create([
            'user_id' => $user3->id,
            'max_shift_per_day' => 1,
            'max_shift_per_day_expiry_date' => '2026-04-30',
            'max_hour_per_week' => 20,
            'max_hour_per_week_expiry_date' => '2026-04-30',
        ]);

        $result = ($this->action)();

        $this->assertCount(2, $result);

        $resultUserIds = $result->pluck('user.id')->toArray();
        $this->assertContains($user1->id, $resultUserIds);
        $this->assertContains($user2->id, $resultUserIds);
        $this->assertNotContains($user3->id, $resultUserIds);

        Carbon::setTestNow();
    }

    public function testIncludesUserConfigWithZeroValues(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $user = User::factory()->create(['staff_no' => 'S012']);
        UserConfig::create([
            'user_id' => $user->id,
            'max_shift_per_day' => 0,
            'max_hour_per_week' => 0,
        ]);

        $result = ($this->action)();

        $this->assertCount(1, $result);
        $this->assertEquals($user->id, $result->first()['user']->id);

        Carbon::setTestNow();
    }
}
