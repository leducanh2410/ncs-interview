<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use App\Models\ExportBatch;
use App\Models\ExportBatchItem;
use App\Models\PdfTemplate;
use App\Models\PdfTemplateField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class UserCustomConfigFilterTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['work.max_shift_per_day' => 2, 'work.max_hour_per_week' => 28]);

        $this->admin = $this->createUserWithPermissions([
            PermissionName::UserList,
            PermissionName::UserConfigList,
            PermissionName::UserConfigEdit,
            PermissionName::ConfigEdit,
            PermissionName::UserExportCsv,
            PermissionName::PdfTemplateExport,
        ]);
    }

    public function testFilterReturnsOnlyUsersWhoseConfigDiffersFromTheDefault(): void
    {
        $customHours = $this->createEmployee();
        $sameAsDefault = $this->createEmployee();
        $withoutConfig = $this->createEmployee();

        $this->saveUserConfig($customHours, ['max_hour_per_week' => 30]);
        $this->saveUserConfig($sameAsDefault);

        $ids = $this->listUserIds(['custom_config' => '1']);

        $this->assertContains($customHours->id, $ids);
        $this->assertNotContains($sameAsDefault->id, $ids);
        $this->assertNotContains($withoutConfig->id, $ids);
        $this->assertNotContains($this->admin->id, $ids);
    }

    public function testFilterMatchesADifferenceInEitherConfig(): void
    {
        $customShifts = $this->createEmployee();
        $customHours = $this->createEmployee();

        $this->saveUserConfig($customShifts, ['max_shift_per_day' => 3]);
        $this->saveUserConfig($customHours, ['max_hour_per_week' => 20]);

        $ids = $this->listUserIds(['custom_config' => '1']);

        $this->assertEqualsCanonicalizing([$customShifts->id, $customHours->id], $ids);
    }

    public function testFilterIgnoresTheConfigPeriod(): void
    {
        $expired = $this->createEmployee();
        $upcoming = $this->createEmployee();

        $this->saveUserConfig($expired, [
            'max_hour_per_week' => 30,
            'max_hour_per_week_start_date' => now()->subYear()->toDateString(),
            'max_hour_per_week_expiry_date' => now()->subMonth()->toDateString(),
        ]);
        $this->saveUserConfig($upcoming, [
            'max_hour_per_week' => 30,
            'max_hour_per_week_start_date' => now()->addMonth()->toDateString(),
            'max_hour_per_week_expiry_date' => now()->addYear()->toDateString(),
        ]);

        $ids = $this->listUserIds(['custom_config' => '1']);

        $this->assertEqualsCanonicalizing([$expired->id, $upcoming->id], $ids);
    }

    public function testFilterComparesAgainstTheSystemConfigOnceItIsSaved(): void
    {
        $thirtyHours = $this->createEmployee();
        $twentyEightHours = $this->createEmployee();

        $this->saveUserConfig($thirtyHours, ['max_hour_per_week' => 30]);
        $this->saveUserConfig($twentyEightHours, ['max_hour_per_week' => 28]);

        $this->assertEquals([$thirtyHours->id], $this->listUserIds(['custom_config' => '1']));

        $this->actingAs($this->admin, 'api')
            ->putJson('/admin/configs', [
                'max_shift_per_day' => 2,
                'max_hour_per_week' => 30,
                'salary_per_hour' => 1000,
                'login_url' => 'https://example.com/login',
            ])
            ->assertOk();

        $this->assertEquals([$twentyEightHours->id], $this->listUserIds(['custom_config' => '1']));
    }

    public function testFilterPicksUpAConfigEditedBackToTheDefault(): void
    {
        $employee = $this->createEmployee();

        $this->saveUserConfig($employee, ['max_hour_per_week' => 30]);
        $this->assertEquals([$employee->id], $this->listUserIds(['custom_config' => '1']));

        $this->saveUserConfig($employee, ['max_hour_per_week' => 28]);
        $this->assertEquals([], $this->listUserIds(['custom_config' => '1']));
    }

    public function testListReturnsCustomConfigsWithTheirPeriod(): void
    {
        $employee = $this->createEmployee();
        $this->saveUserConfig($employee, [
            'max_hour_per_week' => 30,
            'max_hour_per_week_start_date' => '2026-10-01',
            'max_hour_per_week_expiry_date' => '2026-12-31',
        ]);

        $row = $this->findRow(['custom_config' => '1'], $employee->id);

        $this->assertEquals(
            [
                [
                    'key' => 'max_hour_per_week',
                    'value' => 30,
                    'default_value' => 28,
                    'start_date' => '2026-10-01',
                    'expiry_date' => '2026-12-31',
                ],
            ],
            $row['custom_configs']
        );
        $this->assertArrayNotHasKey('user_config', $row);
    }

    public function testListReturnsAnEmptyCustomConfigsForUsersOnTheDefault(): void
    {
        $sameAsDefault = $this->createEmployee();
        $withoutConfig = $this->createEmployee();
        $this->saveUserConfig($sameAsDefault);

        $this->assertSame([], $this->findRow([], $sameAsDefault->id)['custom_configs']);
        $this->assertSame([], $this->findRow([], $withoutConfig->id)['custom_configs']);
    }

    public function testFilterAndFieldAreIgnoredWithoutUserConfigListPermission(): void
    {
        $customHours = $this->createEmployee();
        $withoutConfig = $this->createEmployee();
        $this->saveUserConfig($customHours, ['max_hour_per_week' => 30]);

        $viewer = $this->createUserWithPermissions([PermissionName::UserList]);
        $response = $this->actingAs($viewer, 'api')
            ->getJson('/admin/users?' . http_build_query(['custom_config' => '1', 'page_size' => 100]))
            ->assertOk();

        $rows = collect($response->json('data.data'));
        $this->assertContains($withoutConfig->id, $rows->pluck('id'));
        $this->assertArrayNotHasKey('custom_configs', $rows->firstWhere('id', $customHours->id));
    }

    public function testCsvExportSelectAllAppliesTheFilter(): void
    {
        Queue::fake();
        $customHours = $this->createEmployee();
        $this->createEmployee();
        $this->saveUserConfig($customHours, ['max_hour_per_week' => 30]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/csv-export/batch', $this->csvPayload(['custom_config' => '1']))
            ->assertStatus(202);

        $this->assertSame(1, ExportBatch::query()->latest('id')->firstOrFail()->csv_total_count);
    }

    public function testCsvExportIgnoresTheFilterWithoutUserConfigListPermission(): void
    {
        Queue::fake();
        $customHours = $this->createEmployee();
        $this->createEmployee();
        $this->saveUserConfig($customHours, ['max_hour_per_week' => 30]);

        $exporter = $this->createUserWithPermissions([PermissionName::UserExportCsv]);
        $this->actingAs($exporter, 'api')
            ->postJson('/admin/csv-export/batch', $this->csvPayload(['custom_config' => '1']))
            ->assertStatus(202);

        $this->assertSame(
            User::query()->where('is_disabled', false)->count(),
            ExportBatch::query()->latest('id')->firstOrFail()->csv_total_count
        );
    }

    public function testPdfExportSelectAllAppliesTheFilter(): void
    {
        Queue::fake();
        $customHours = $this->createEmployee();
        $this->createEmployee();
        $this->saveUserConfig($customHours, ['max_hour_per_week' => 30]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/admin/pdf-export/batch-export', [
                'template_id' => $this->createPdfTemplate()->id,
                'user_ids' => [],
                'select_all' => true,
                'filters' => ['custom_config' => '1'],
            ])
            ->assertStatus(202);

        $this->assertEquals(
            [$customHours->id],
            ExportBatchItem::query()->where('export_batch_id', $response->json('data.id'))->pluck('user_id')->all()
        );
    }

    private function createEmployee(): User
    {
        return User::factory()->create(['is_disabled' => false]);
    }

    private function saveUserConfig(User $user, array $overrides = []): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson(
                "/admin/users/{$user->id}/configs",
                array_merge(['max_shift_per_day' => 2, 'max_hour_per_week' => 28], $overrides)
            )
            ->assertOk();
    }

    private function listUserIds(array $query): array
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/admin/users?' . http_build_query(array_merge(['page_size' => 100], $query)))
            ->assertOk();

        return collect($response->json('data.data'))->pluck('id')->sort()->values()->all();
    }

    private function findRow(array $query, int $userId): array
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/admin/users?' . http_build_query(array_merge(['page_size' => 100], $query)))
            ->assertOk();

        return collect($response->json('data.data'))->firstWhere('id', $userId);
    }

    private function csvPayload(array $filters): array
    {
        return [
            'selected_keys' => ['last_name'],
            'ordered_keys' => ['last_name'],
            'user_ids' => [],
            'select_all' => true,
            'filters' => array_merge(['is_disabled' => '0'], $filters),
        ];
    }

    private function createPdfTemplate(): PdfTemplate
    {
        $template = PdfTemplate::query()->create([
            'name' => 'Test Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);
        PdfTemplateField::query()->create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10,
            'y' => 20,
            'width' => 30,
            'height' => 5,
            'data_source_key' => 'user.email',
            'fill_mode' => 'text',
            'text_config' => json_encode([
                'font_weight' => 'normal',
                'font_size' => 12,
                'h_align' => 'left',
                'v_align' => 'middle',
                'letter_spacing' => 'auto',
                'overflow' => 'wrap',
            ]),
        ]);

        return $template;
    }
}
