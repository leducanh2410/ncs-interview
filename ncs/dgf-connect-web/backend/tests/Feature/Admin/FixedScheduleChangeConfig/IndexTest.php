<?php

namespace Tests\Feature\Admin\FixedScheduleChangeConfig;

use App\Enums\PermissionName;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function testRequiresFixedScheduleChangeConfigListPermission(): void
    {
        $manager = User::factory()->create();

        $response = $this->actingAs($manager, 'api')->getJson('/admin/fixed-schedule-change-configs');

        $response->assertForbidden();
    }

    public function testReturnsOnlyManagedWorkplaces(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigList,
            $this->workplace->id
        );

        $response = $this->actingAs($manager, 'api')->getJson('/admin/fixed-schedule-change-configs');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($this->workplace->id, $data[0]['workplace_id']);
    }

    public function testGlobalAdminSeesAll(): void
    {
        $globalAdmin = User::factory()->create();
        $this->giveGlobalPermission($globalAdmin, PermissionName::FixedScheduleChangeConfigList);

        $response = $this->actingAs($globalAdmin, 'api')->getJson('/admin/fixed-schedule-change-configs');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function testAllowsAccessWithOnlyChangeRequestListPermission(): void
    {
        // An admin who can only see change requests (not manage the config) still needs this
        // list, to render the round's deadline on the change-requests screen.
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );

        $response = $this->actingAs($manager, 'api')->getJson('/admin/fixed-schedule-change-configs');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($this->workplace->id, $data[0]['workplace_id']);
    }
}
