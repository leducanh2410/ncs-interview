<?php

namespace Tests\Unit\Actions\Admin\FixedSchedule;

use App\Actions\Admin\FixedSchedule\GetFixedScheduleChangeConfigsAction;
use App\Enums\PermissionName;
use App\Models\FixedScheduleChangeConfig;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class GetFixedScheduleChangeConfigsActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private GetFixedScheduleChangeConfigsAction $action;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(GetFixedScheduleChangeConfigsAction::class);

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

    public function testListsAllManagedWorkplacesEvenWithoutConfigRow(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigList,
            $this->workplace->id
        );

        $result = ($this->action)($manager);

        $data = $result->getData();
        $this->assertCount(1, $data);
        $this->assertSame($this->workplace->id, $data->first()['workplace_id']);
        $this->assertNull($data->first()['id']);
        $this->assertFalse($data->first()['is_enabled']);
    }

    public function testComputesIsWindowOpenCorrectlyWhenEnabledAndBeforeDeadline(): void
    {
        $this->travelTo('2026-07-16');
        FixedScheduleChangeConfig::create([
            'workplace_id' => $this->workplace->id,
            'is_enabled' => true,
            'deadline' => '2026-07-20',
        ]);

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigList,
            $this->workplace->id
        );

        $result = ($this->action)($manager);

        $this->assertTrue($result->getData()->first()['is_window_open']);
    }

    public function testComputesIsWindowOpenFalseWhenDeadlinePassed(): void
    {
        $this->travelTo('2026-07-21');
        FixedScheduleChangeConfig::create([
            'workplace_id' => $this->workplace->id,
            'is_enabled' => true,
            'deadline' => '2026-07-20',
        ]);

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigList,
            $this->workplace->id
        );

        $result = ($this->action)($manager);

        $this->assertFalse($result->getData()->first()['is_window_open']);
    }

    public function testComputesIsWindowOpenFalseWhenDisabled(): void
    {
        $this->travelTo('2026-07-16');
        FixedScheduleChangeConfig::create([
            'workplace_id' => $this->workplace->id,
            'is_enabled' => false,
            'deadline' => '2026-07-20',
        ]);

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigList,
            $this->workplace->id
        );

        $result = ($this->action)($manager);

        $this->assertFalse($result->getData()->first()['is_window_open']);
    }

    public function testUnionsChangeConfigAndChangeRequestScopesWithoutLeakingOtherWorkplaces(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigList,
            $this->workplace->id
        );
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->otherWorkplace->id
        );

        $result = ($this->action)($manager);

        $workplaceIds = $result->getData()->pluck('workplace_id')->sort()->values()->all();
        $this->assertSame([$this->workplace->id, $this->otherWorkplace->id], $workplaceIds);
    }

    public function testGrantsAccessThroughChangeRequestListPermissionAlone(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );

        $result = ($this->action)($manager);

        $data = $result->getData();
        $this->assertCount(1, $data);
        $this->assertSame($this->workplace->id, $data->first()['workplace_id']);
    }
}
