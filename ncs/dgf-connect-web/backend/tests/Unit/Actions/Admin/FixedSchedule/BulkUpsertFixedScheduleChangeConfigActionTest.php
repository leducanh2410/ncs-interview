<?php

namespace Tests\Unit\Actions\Admin\FixedSchedule;

use App\Actions\Admin\FixedSchedule\BulkUpsertFixedScheduleChangeConfigAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\FixedScheduleChangeConfig;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class BulkUpsertFixedScheduleChangeConfigActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private BulkUpsertFixedScheduleChangeConfigAction $action;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    private Workplace $unmanagedWorkplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(BulkUpsertFixedScheduleChangeConfigAction::class);

        $this->workplaceA = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->workplaceB = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $this->unmanagedWorkplace = Workplace::create([
            'name' => 'Workplace C',
            'address' => 'Addr C',
            'latitude' => 37.0,
            'longitude' => 141.0,
        ]);
    }

    private function giveManagerAccessToBoth(User $manager): void
    {
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplaceA->id
        );
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplaceB->id
        );
    }

    public function testAppliesSameValuesToAllSelectedWorkplaces(): void
    {
        $manager = User::factory()->create();
        $this->giveManagerAccessToBoth($manager);

        $result = ($this->action)(
            $manager,
            [$this->workplaceA->id, $this->workplaceB->id],
            ['is_enabled' => true, 'deadline' => '2026-08-15']
        );

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('fixed_schedule_change_configs', [
            'workplace_id' => $this->workplaceA->id,
            'is_enabled' => true,
            'deadline' => '2026-08-15',
        ]);
        $this->assertDatabaseHas('fixed_schedule_change_configs', [
            'workplace_id' => $this->workplaceB->id,
            'is_enabled' => true,
            'deadline' => '2026-08-15',
        ]);
    }

    public function testCreatesConfigForWorkplacesWithNoneExisting(): void
    {
        $manager = User::factory()->create();
        $this->giveManagerAccessToBoth($manager);

        $this->assertDatabaseCount('fixed_schedule_change_configs', 0);

        ($this->action)(
            $manager,
            [$this->workplaceA->id, $this->workplaceB->id],
            ['is_enabled' => false, 'deadline' => null]
        );

        $this->assertDatabaseCount('fixed_schedule_change_configs', 2);
    }

    public function testRejectsEntireBatchWhenAnyWorkplaceUnmanaged(): void
    {
        $manager = User::factory()->create();
        $this->giveManagerAccessToBoth($manager);

        $result = ($this->action)(
            $manager,
            [$this->workplaceA->id, $this->unmanagedWorkplace->id],
            ['is_enabled' => true, 'deadline' => '2026-08-15']
        );

        $this->assertSame(ErrorCode::Forbidden, $result->getError());
    }

    public function testAppliesNothingWhenBatchRejected(): void
    {
        $manager = User::factory()->create();
        $this->giveManagerAccessToBoth($manager);

        ($this->action)(
            $manager,
            [$this->workplaceA->id, $this->unmanagedWorkplace->id],
            ['is_enabled' => true, 'deadline' => '2026-08-15']
        );

        $this->assertDatabaseCount('fixed_schedule_change_configs', 0);
    }
}
