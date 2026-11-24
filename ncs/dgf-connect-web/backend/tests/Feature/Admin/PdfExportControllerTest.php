<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\PdfTemplate\GeneratePdfAction;
use App\Enums\ExportBatchItemStatus;
use App\Enums\ExportBatchStatus;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Jobs\ProcessExportChunkJob;
use App\Models\ExportBatch;
use App\Models\ExportBatchItem;
use App\Models\PdfTemplate;
use App\Models\PdfTemplateField;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PdfExportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearPdfTemplateLocks();
        $this->admin = User::factory()->create();
    }

    // ---------------------------------------------------------------
    // Preview
    // ---------------------------------------------------------------

    public function testPreviewReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/pdf-templates/{$template->id}/preview");

        $response->assertStatus(403);
    }

    public function testPreviewReturnsPdfContent(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplateWithField();

        $mockAction = Mockery::mock(GeneratePdfAction::class);
        $mockAction->shouldReceive('__invoke')->once()->andReturn('%PDF-1.4 mock pdf content');
        $this->app->instance(GeneratePdfAction::class, $mockAction);

        $response = $this->actingAs($this->admin, 'api')->post("/admin/pdf-templates/{$template->id}/preview");

        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
    }

    // ---------------------------------------------------------------
    // Batch Export
    // ---------------------------------------------------------------

    public function testBatchExportReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();
        $user = User::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [$user->id],
        ]);

        $response->assertStatus(403);
    }

    public function testBatchExportCreatesBatchAndItems(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplateWithField();
        $users = User::factory()->count(3)->create();
        $userIds = $users->pluck('id')->toArray();

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => $userIds,
        ]);

        $response->assertStatus(202);

        $batchId = $response->json('data.id');
        $this->assertNotNull($batchId);

        $this->assertDatabaseHas('export_batches', [
            'id' => $batchId,
            'pdf_template_id' => $template->id,
            'exported_by' => $this->admin->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseCount('export_batch_items', 3);

        foreach ($userIds as $userId) {
            $this->assertDatabaseHas('export_batch_items', [
                'export_batch_id' => $batchId,
                'user_id' => $userId,
                'status' => 'pending',
            ]);
        }

        Queue::assertPushed(ProcessExportChunkJob::class);
    }

    public function testBatchExportReturns423WhenTemplateLocked(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplate();
        $otherUser = User::factory()->create();
        $targetUser = User::factory()->create();

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $otherUser->id, 'editing', $otherUser->name);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [$targetUser->id],
        ]);

        $response->assertStatus(423);
        Queue::assertNotPushed(ProcessExportChunkJob::class);
    }

    public function testBatchExportValidatesUserIdsRequired(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [],
            'select_all' => false,
        ]);

        $response->assertStatus(422);
    }

    public function testBatchExportValidatesTemplateExists(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => 99999,
            'user_ids' => [1],
        ]);

        $response->assertStatus(422);
    }

    public function testBatchExportSelectAllResolvesUsers(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplateWithField();
        User::factory()
            ->count(5)
            ->create(['is_disabled' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [],
            'select_all' => true,
            'filters' => null,
        ]);

        $response->assertStatus(202);

        $batchId = $response->json('data.id');
        $itemCount = ExportBatchItem::where('export_batch_id', $batchId)->count();
        $this->assertGreaterThanOrEqual(5, $itemCount);
    }

    public function testBatchExportSelectAllFiltersByName(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplateWithField();
        $matchingUser = User::factory()->create([
            'last_name' => 'Tanaka',
            'first_name' => 'Taro',
            'is_disabled' => false,
        ]);
        User::factory()->create(['last_name' => 'Suzuki', 'first_name' => 'Jiro', 'is_disabled' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [],
            'select_all' => true,
            'filters' => ['name' => 'Tanaka'],
        ]);

        $response->assertStatus(202);

        $batchId = $response->json('data.id');
        $itemUserIds = ExportBatchItem::where('export_batch_id', $batchId)->pluck('user_id')->toArray();
        $this->assertContains($matchingUser->id, $itemUserIds);
    }

    public function testBatchExportSelectAllFiltersByWorkplace(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplateWithField();
        $workplace = Workplace::query()->create([
            'name' => 'Office A',
            'address' => 'Tokyo',
            'latitude' => '35.6762',
            'longitude' => '139.6503',
        ]);
        $userWithWorkplace = User::factory()->create(['is_disabled' => false]);
        $userWithWorkplace->workplaces()->attach($workplace->id);
        User::factory()->create(['is_disabled' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [],
            'select_all' => true,
            'filters' => ['workplace_id' => $workplace->id],
        ]);

        $response->assertStatus(202);

        $batchId = $response->json('data.id');
        $itemUserIds = ExportBatchItem::where('export_batch_id', $batchId)->pluck('user_id')->toArray();
        $this->assertContains($userWithWorkplace->id, $itemUserIds);
    }

    public function testBatchExportSelectAllFiltersByEmail(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplateWithField();
        $matchingUser = User::factory()->create(['email' => 'unique-test-email@example.com', 'is_disabled' => false]);
        User::factory()->create(['email' => 'other@example.com', 'is_disabled' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [],
            'select_all' => true,
            'filters' => ['email' => 'unique-test-email'],
        ]);

        $response->assertStatus(202);

        $batchId = $response->json('data.id');
        $itemUserIds = ExportBatchItem::where('export_batch_id', $batchId)->pluck('user_id')->toArray();
        $this->assertContains($matchingUser->id, $itemUserIds);
        $this->assertCount(1, $itemUserIds);
    }

    public function testBatchExportSelectAllFiltersByStaffNo(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplateWithField();
        $matchingUser = User::factory()->create(['staff_no' => 'EMP-001', 'is_disabled' => false]);
        User::factory()->create(['staff_no' => 'EMP-002', 'is_disabled' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [],
            'select_all' => true,
            'filters' => ['staff_no' => 'EMP-001'],
        ]);

        $response->assertStatus(202);

        $batchId = $response->json('data.id');
        $itemUserIds = ExportBatchItem::where('export_batch_id', $batchId)->pluck('user_id')->toArray();
        $this->assertContains($matchingUser->id, $itemUserIds);
        $this->assertCount(1, $itemUserIds);
    }

    public function testBatchExportSelectAllFiltersByRole(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplateWithField();
        $role = Role::query()->firstOrCreate(['name' => 'manager'], ['display_name' => 'Manager']);
        $userWithRole = User::factory()->create(['is_disabled' => false]);
        $userWithRole->roles()->attach($role->id);
        User::factory()->create(['is_disabled' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [],
            'select_all' => true,
            'filters' => ['role' => 'manager'],
        ]);

        $response->assertStatus(202);

        $batchId = $response->json('data.id');
        $itemUserIds = ExportBatchItem::where('export_batch_id', $batchId)->pluck('user_id')->toArray();
        $this->assertContains($userWithRole->id, $itemUserIds);
    }

    public function testBatchExportSelectAllExcludesDisabledByDefault(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplateWithField();
        $enabledUser = User::factory()->create(['is_disabled' => false]);
        $disabledUser = User::factory()->create(['is_disabled' => true]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [],
            'select_all' => true,
            'filters' => [],
        ]);

        $response->assertStatus(202);

        $batchId = $response->json('data.id');
        $itemUserIds = ExportBatchItem::where('export_batch_id', $batchId)->pluck('user_id')->toArray();
        $this->assertContains($enabledUser->id, $itemUserIds);
        $this->assertNotContains($disabledUser->id, $itemUserIds);
    }

    public function testBatchExportLocksTemplate(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplateWithField();
        $user = User::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-export/batch-export', [
            'template_id' => $template->id,
            'user_ids' => [$user->id],
        ]);

        $response->assertStatus(202);

        $lockService = app(PdfTemplateLockService::class);
        $this->assertTrue($lockService->isLocked($template->id));
    }

    // ---------------------------------------------------------------
    // List Batch Items
    // ---------------------------------------------------------------

    public function testListBatchItemsReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();
        $user = User::factory()->create();
        $batch = $this->createBatch($template, $this->admin, [$user->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/batches/{$batch->id}/items");

        $response->assertStatus(403);
    }

    public function testListBatchItemsReturnsPaginatedItems(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $template = $this->createTemplate();
        $users = User::factory()->count(3)->create();
        $batch = $this->createBatch($template, $this->admin, $users->pluck('id')->toArray());

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/batches/{$batch->id}/items");

        $response->assertStatus(200)->assertJsonStructure([
            'data' => [
                'data' => [
                    '*' => ['id', 'user_id', 'status', 'user'],
                ],
                'current_page',
                'total',
            ],
        ]);

        $this->assertEquals(3, $response->json('data.total'));
    }

    public function testListBatchItemsUsesShortenedUserNameForSriLankaNationality(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $template = $this->createTemplate();
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $batch = $this->createBatch($template, $this->admin, [$user->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/batches/{$batch->id}/items");

        $response->assertStatus(200)->assertJsonPath('data.data.0.user.name', 'THARINDU RAVISHANN');
    }

    // ---------------------------------------------------------------
    // Download Item
    // ---------------------------------------------------------------

    public function testDownloadItemReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();
        $user = User::factory()->create();
        $batch = $this->createBatch($template, $this->admin, [$user->id]);
        $item = $batch->items()->first();

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/items/{$item->id}/download");

        $response->assertStatus(403);
    }

    public function testDownloadItemReturnsUrlForCompletedItem(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplate();
        $user = User::factory()->create();
        $batch = $this->createBatch($template, $this->admin, [$user->id]);

        $item = $batch->items()->first();
        $item->update([
            'status' => ExportBatchItemStatus::Completed,
            'file_path' => 'pdf/1/2026/1/test.pdf',
            'file_name' => 'test.pdf',
        ]);

        Storage::put('pdf/1/2026/1/test.pdf', 'pdf content');

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/items/{$item->id}/download");

        $response->assertStatus(200);
        $this->assertArrayHasKey('download_url', $response->json('data'));
    }

    public function testDownloadItemReturns404ForPendingItem(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplate();
        $user = User::factory()->create();
        $batch = $this->createBatch($template, $this->admin, [$user->id]);

        $item = $batch->items()->first();

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/items/{$item->id}/download");

        $response->assertStatus(404);
    }

    // ---------------------------------------------------------------
    // Download Batch Zip
    // ---------------------------------------------------------------

    public function testDownloadBatchReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();
        $user = User::factory()->create();
        $batch = $this->createBatch($template, $this->admin, [$user->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/batches/{$batch->id}/download");

        $response->assertStatus(403);
    }

    public function testDownloadBatchReturnsUrlForCompletedBatch(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplate();
        $user = User::factory()->create();
        $batch = $this->createBatch(
            $template,
            $this->admin,
            [$user->id],
            [
                'status' => ExportBatchStatus::Completed,
                'zip_file_paths' => ['pdf/1/2026/1/all.zip'],
            ]
        );

        $item = $batch->items()->first();
        $item->update([
            'status' => ExportBatchItemStatus::Completed,
            'file_path' => 'pdf/1/2026/1/test.pdf',
        ]);

        Storage::put('pdf/1/2026/1/all.zip', 'zip content');

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/batches/{$batch->id}/download");

        $response->assertStatus(200)->assertJsonCount(1, 'data.download_urls');
        $this->assertArrayHasKey('download_url', $response->json('data'));
    }

    public function testDownloadBatchReturnsMultipleUrlsForSplitArchives(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplate();
        $user = User::factory()->create();
        $batch = $this->createBatch(
            $template,
            $this->admin,
            [$user->id],
            [
                'status' => ExportBatchStatus::Completed,
                'zip_file_paths' => ['pdf/1/2026/1/all_part_01.zip', 'pdf/1/2026/1/all_part_02.zip'],
            ]
        );

        $batch
            ->items()
            ->first()
            ->update([
                'status' => ExportBatchItemStatus::Completed,
                'file_path' => 'pdf/1/2026/1/test.pdf',
            ]);

        Storage::put('pdf/1/2026/1/all_part_01.zip', 'zip content 1');
        Storage::put('pdf/1/2026/1/all_part_02.zip', 'zip content 2');

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/batches/{$batch->id}/download");

        $response->assertStatus(200)->assertJsonCount(2, 'data.download_urls');
    }

    public function testDownloadBatchReturns404ForPendingBatch(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplate();
        $user = User::factory()->create();
        $batch = $this->createBatch($template, $this->admin, [$user->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/batches/{$batch->id}/download");

        $response->assertStatus(404);
    }

    public function testDownloadBatchReturns422WhenNoCompletedItems(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $template = $this->createTemplate();
        $user = User::factory()->create();
        $batch = $this->createBatch(
            $template,
            $this->admin,
            [$user->id],
            [
                'status' => ExportBatchStatus::Completed,
                'zip_file_paths' => ['pdf/1/2026/1/all.zip'],
            ]
        );

        Storage::put('pdf/1/2026/1/all.zip', 'zip content');

        // Leave all items as pending (none completed)

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-export/batches/{$batch->id}/download");

        $response->assertStatus(422);
    }

    private function clearPdfTemplateLocks(): void
    {
        $store = Cache::getStore();

        if (!$store instanceof RedisStore) {
            Cache::flush();

            return;
        }

        $pattern = $store->getPrefix() . 'pdf_template:lock:*';
        $keys = $store->connection()->keys($pattern);

        if (!empty($keys)) {
            $store->connection()->del(...$keys);
        }
    }

    private function grantPermission(User $user, PermissionName $permission): void
    {
        $perm = Permission::firstOrCreate(['name' => $permission->value], ['display_name' => $permission->value]);
        $user->permissions()->syncWithoutDetaching([$perm->id]);
    }

    private function grantAllPdfPermissions(User $user): void
    {
        $this->grantPermission($user, PermissionName::PdfTemplateList);
        $this->grantPermission($user, PermissionName::PdfTemplateCreate);
        $this->grantPermission($user, PermissionName::PdfTemplateUpdate);
        $this->grantPermission($user, PermissionName::PdfTemplateDelete);
        $this->grantPermission($user, PermissionName::PdfTemplateExport);
    }

    private function createTemplate(array $attrs = []): PdfTemplate
    {
        return PdfTemplate::query()->create(
            array_merge(
                [
                    'name' => 'Test Template',
                    'file_path' => 'pdf-templates/test.pdf',
                    'file_name' => 'test.pdf',
                    'page_count' => 1,
                ],
                $attrs
            )
        );
    }

    private function createTemplateWithField(array $templateAttrs = []): PdfTemplate
    {
        $template = $this->createTemplate($templateAttrs);
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

    private function createBatch(
        PdfTemplate $template,
        User $exporter,
        array $userIds,
        array $batchAttrs = []
    ): ExportBatch {
        $batch = ExportBatch::query()->create(
            array_merge(
                [
                    'pdf_template_id' => $template->id,
                    'exported_by' => $exporter->id,
                    'status' => ExportBatchStatus::Pending,
                ],
                $batchAttrs
            )
        );

        foreach ($userIds as $userId) {
            ExportBatchItem::query()->create([
                'export_batch_id' => $batch->id,
                'user_id' => $userId,
                'status' => ExportBatchItemStatus::Pending,
            ]);
        }

        return $batch;
    }
}
