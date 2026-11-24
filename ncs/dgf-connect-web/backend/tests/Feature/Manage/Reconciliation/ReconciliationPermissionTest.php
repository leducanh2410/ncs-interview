<?php

namespace Tests\Feature\Manage\Reconciliation;

use App\Actions\Admin\GetMasterDataAction;
use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAmazonKadouWorkbook;
use Tests\Concerns\SeedsReconciliationFixtures;
use Tests\TestCase;

class ReconciliationPermissionTest extends TestCase
{
    use BuildsAmazonKadouWorkbook;
    use RefreshDatabase;
    use SeedsReconciliationFixtures;

    public function testRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->getJson('/manage/work-history-reconciliations')->assertForbidden();
    }

    public function testWorkplaceScopedGrantStillMatchesOtherWorkplace(): void
    {
        $admin = User::factory()->create();
        $workplaceA = $this->makeWorkplace('A');
        $workplaceB = $this->makeWorkplace('B');
        $this->giveWorkplaceScopedPermission($admin, PermissionName::ReconciliationWork, $workplaceA->id);

        $seeded = $this->makeEmployeeWithCheckIn($workplaceB, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);

        $path = sys_get_temp_dir() . '/recon-perm-' . uniqid() . '.xlsx';
        $this->writeAmazonKadouFile($path, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $response = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new \Illuminate\Http\UploadedFile($path, 'amazon.xlsx', null, null, true),
        ]);

        $response->assertCreated();
        $this->assertSame($seeded['checkIn']->id, $response->json('data.rows.0.check_in_id'));
        @unlink($path);
    }

    public function testWorkplaceScopedGrantCanSearchUsers(): void
    {
        $admin = User::factory()->create();
        $workplaceA = $this->makeWorkplace('A');
        $this->giveWorkplaceScopedPermission($admin, PermissionName::ReconciliationWork, $workplaceA->id);
        User::factory()->create([
            'first_name' => 'Searchable',
            'staff_no' => 'S-SCOPE-1',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/manage/work-history-reconciliations/users?q=Searchable')
            ->assertOk()
            ->assertJsonPath('data.0.staff_no', 'S-SCOPE-1');
    }

    public function testMigrationCreatesReconciliationPermission(): void
    {
        $this->assertNotNull(Permission::query()->where('name', PermissionName::ReconciliationWork->value)->first());
    }

    public function testWorkplaceAccessHasNoReconciliationKey(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $result = (new GetMasterDataAction(app(\App\Actions\Workplace\ShouldShowTestWorkplacesAction::class)))($admin);
        $access = $result->getData()['workplace_access'];
        $this->assertArrayNotHasKey('reconciliation', $access);
    }
}
