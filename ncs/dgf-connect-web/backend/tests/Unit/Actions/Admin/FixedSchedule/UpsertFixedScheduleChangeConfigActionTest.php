<?php

namespace Tests\Unit\Actions\Admin\FixedSchedule;

use App\Actions\Admin\FixedSchedule\UpsertFixedScheduleChangeConfigAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\FixedScheduleChangeConfig;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class UpsertFixedScheduleChangeConfigActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private UpsertFixedScheduleChangeConfigAction $action;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(UpsertFixedScheduleChangeConfigAction::class);

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->otherWorkplace = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
    }

    public function testCreatesConfigWhenNoneExists(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplace->id
        );

        $result = ($this->action)($manager, $this->workplace, ['is_enabled' => true, 'deadline' => '2026-08-15']);

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('fixed_schedule_change_configs', [
            'workplace_id' => $this->workplace->id,
            'is_enabled' => true,
            'deadline' => '2026-08-15',
        ]);
    }

    public function testUpdatesExistingConfig(): void
    {
        $config = FixedScheduleChangeConfig::create([
            'workplace_id' => $this->workplace->id,
            'is_enabled' => false,
            'deadline' => null,
        ]);

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplace->id
        );

        ($this->action)($manager, $this->workplace, ['is_enabled' => true, 'deadline' => '2026-09-01']);

        $this->assertDatabaseCount('fixed_schedule_change_configs', 1);
        $this->assertDatabaseHas('fixed_schedule_change_configs', [
            'id' => $config->id,
            'is_enabled' => true,
            'deadline' => '2026-09-01',
        ]);
    }

    public function testAllowsPastDeadline(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplace->id
        );

        $result = ($this->action)($manager, $this->workplace, ['is_enabled' => true, 'deadline' => '2020-01-01']);

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('fixed_schedule_change_configs', [
            'workplace_id' => $this->workplace->id,
            'deadline' => '2020-01-01',
        ]);
    }

    public function testRejectsUnmanagedWorkplace(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->otherWorkplace->id
        );

        $result = ($this->action)($manager, $this->workplace, ['is_enabled' => true, 'deadline' => '2026-08-15']);

        $this->assertSame(ErrorCode::Forbidden, $result->getError());
        $this->assertDatabaseCount('fixed_schedule_change_configs', 0);
    }
}
