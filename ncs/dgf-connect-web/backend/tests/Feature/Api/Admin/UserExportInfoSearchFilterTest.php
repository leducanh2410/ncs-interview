<?php

namespace Tests\Feature\Api\Admin;

use App\Http\Requests\Admin\PdfExport\BatchExportRequest;
use App\Models\ExportBatch;
use App\Models\Permission;
use App\Enums\PermissionName;
use App\Models\User;
use App\Models\UserInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserExportInfoSearchFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create();
    }

    private function grantPermission(User $user, PermissionName $permission): void
    {
        $perm = Permission::firstOrCreate(['name' => $permission->value], ['display_name' => $permission->value]);
        $user->permissions()->syncWithoutDetaching([$perm->id]);
    }

    private function createUserWithPassport(string $passportNumber): User
    {
        $user = User::factory()->create();
        UserInfo::factory()->create([
            'user_id' => $user->id,
            'passport_number' => $passportNumber,
        ]);

        return $user;
    }

    public function testCsvExportKeepsInfoSearchFiltersThroughValidation(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/csv-export/batch', [
            'selected_keys' => ['last_name', 'first_name'],
            'ordered_keys' => ['last_name', 'first_name'],
            'user_ids' => [],
            'select_all' => true,
            'filters' => [
                'is_disabled' => '0',
                'info_search_field' => 'passport_number',
                'info_search_value' => 'PASS',
            ],
        ]);

        $response->assertStatus(202);

        $batch = ExportBatch::query()->findOrFail($response->json('data.id'));

        $this->assertSame('passport_number', $batch->meta['filters']['info_search_field']);
        $this->assertSame('PASS', $batch->meta['filters']['info_search_value']);
    }

    public function testCsvExportRejectsInfoSearchFieldOutsideAllowList(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/csv-export/batch', [
                'selected_keys' => ['last_name'],
                'ordered_keys' => ['last_name'],
                'user_ids' => [],
                'select_all' => true,
                'filters' => [
                    'is_disabled' => '0',
                    'info_search_field' => 'nationality',
                    'info_search_value' => 'Vietnam',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'E_UNPROCESSABLE_ENTITY')
            ->assertJsonStructure(['data' => ['filters.info_search_field']]);
    }

    public function testPdfBatchExportResolvesOnlyUsersMatchingInfoSearchFilters(): void
    {
        $match = $this->createUserWithPassport('PASS123');
        $other = $this->createUserWithPassport('OTHER999');

        $request = BatchExportRequest::create('/admin/pdf-templates/batch-export', 'POST', [
            'select_all' => true,
            'filters' => [
                'is_disabled' => '0',
                'info_search_field' => 'passport_number',
                'info_search_value' => 'PASS',
            ],
        ]);

        $resolved = $request->getResolvedUserIds();

        $this->assertContains($match->id, $resolved);
        $this->assertNotContains($other->id, $resolved);
        $this->assertNotContains($this->admin->id, $resolved);
    }
}
