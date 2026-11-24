<?php

namespace Tests\Feature\Admin;

use App\Enums\PermissionName;
use App\Models\PdfTemplate;
use App\Models\PdfTemplateField;
use App\Models\Permission;
use App\Models\User;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $testPdfPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearPdfTemplateLocks();
        $this->admin = User::factory()->create();
        $this->testPdfPath = base_path('tests/Fixtures/test_template.pdf');
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

    private function createTestPdfUpload(): UploadedFile
    {
        return new UploadedFile($this->testPdfPath, 'test_template.pdf', 'application/pdf', null, true);
    }

    private function makeFieldData(array $overrides = []): array
    {
        return array_merge(
            [
                'page' => 1,
                'shape' => 'rectangle',
                'x' => 10.0,
                'y' => 20.0,
                'width' => 30.0,
                'height' => 5.0,
                'data_source_key' => 'user.email',
                'placeholder' => null,
                'fill_mode' => 'text',
                'text_config' => [
                    'font_weight' => 'normal',
                    'font_size' => 12,
                    'h_align' => 'left',
                    'v_align' => 'middle',
                    'letter_spacing' => 'auto',
                    'overflow' => 'wrap',
                ],
                'shape_config' => null,
            ],
            $overrides
        );
    }

    // ---------------------------------------------------------------
    // Index
    // ---------------------------------------------------------------

    public function testIndexReturns403WithoutPermission(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/pdf-templates');

        $response->assertStatus(403);
    }

    public function testIndexReturnsPaginatedList(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $this->createTemplate(['name' => 'Template A']);
        $this->createTemplate(['name' => 'Template B']);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/pdf-templates');

        $response->assertStatus(200)->assertJsonStructure([
            'data' => [
                'data' => [
                    '*' => ['id', 'name', 'file_name', 'page_count', 'field_count'],
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);

        $this->assertEquals(2, $response->json('data.total'));
    }

    public function testIndexFiltersByName(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $this->createTemplate(['name' => 'Residence Card']);
        $this->createTemplate(['name' => 'Contract']);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/pdf-templates?name=Residence');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('Residence Card', $response->json('data.data.0.name'));
    }

    public function testIndexExcludesSoftDeleted(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $template = $this->createTemplate(['name' => 'Deleted One']);
        $template->delete();
        $this->createTemplate(['name' => 'Active One']);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/pdf-templates');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('Active One', $response->json('data.data.0.name'));
    }

    // ---------------------------------------------------------------
    // Store
    // ---------------------------------------------------------------

    public function testStoreReturns403WithoutPermission(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-templates', [
            'name' => 'New Template',
        ]);

        $response->assertStatus(403);
    }

    public function testStoreCreatesTemplateWithValidPdf(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::PdfTemplateCreate);

        $file = $this->createTestPdfUpload();

        $response = $this->actingAs($this->admin, 'api')->post('/admin/pdf-templates', [
            'name' => 'New Template',
            'file' => $file,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pdf_templates', [
            'name' => 'New Template',
        ]);
    }

    public function testStoreRejectsNonPdfFile(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateCreate);

        $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-templates', [
            'name' => 'Bad File',
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function testStoreRejectsOversizedFile(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateCreate);

        $file = UploadedFile::fake()->create('large.pdf', 6000, 'application/pdf');

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-templates', [
            'name' => 'Large File',
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function testStoreRejectsDuplicateName(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::PdfTemplateCreate);

        $this->createTemplate(['name' => 'Existing']);

        $file = $this->createTestPdfUpload();

        $response = $this->actingAs($this->admin, 'api')->post('/admin/pdf-templates', [
            'name' => 'Existing',
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function testStoreRejectsMissingName(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateCreate);

        $file = $this->createTestPdfUpload();

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/pdf-templates', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // Show
    // ---------------------------------------------------------------

    public function testShowReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-templates/{$template->id}");

        $response->assertStatus(403);
    }

    public function testShowReturnsTemplateWithFields(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $template = $this->createTemplate();
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

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-templates/{$template->id}");

        $response->assertStatus(200)->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'file_path',
                'file_name',
                'page_count',
                'field_count',
                'fields' => [
                    '*' => ['id', 'page', 'shape', 'x', 'y', 'width', 'height', 'data_source_key', 'fill_mode'],
                ],
            ],
        ]);

        $this->assertCount(1, $response->json('data.fields'));
    }

    public function testShowReturns404ForNonexistent(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/pdf-templates/99999');

        $response->assertStatus(404);
    }

    // ---------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------

    public function testUpdateReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}", [
            'name' => 'Updated',
        ]);

        $response->assertStatus(403);
    }

    public function testUpdateChangesTemplateName(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate(['name' => 'Old Name']);

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pdf_templates', [
            'id' => $template->id,
            'name' => 'New Name',
        ]);
    }

    public function testUpdateReturns423WhenLockedByAnotherUser(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();
        $otherUser = User::factory()->create();

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $otherUser->id, 'editing', $otherUser->name);

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}", [
            'name' => 'Blocked Update',
        ]);

        $response->assertStatus(423);
    }

    public function testUpdateSucceedsWhenLockedBySameUser(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate(['name' => 'Original']);

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $this->admin->id, 'editing', $this->admin->name);

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}", [
            'name' => 'Updated By Owner',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pdf_templates', [
            'id' => $template->id,
            'name' => 'Updated By Owner',
        ]);
    }

    // ---------------------------------------------------------------
    // Destroy (soft-delete)
    // ---------------------------------------------------------------

    public function testDestroyReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/admin/pdf-templates/{$template->id}");

        $response->assertStatus(403);
    }

    public function testDestroySoftDeletesTemplate(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateDelete);

        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/admin/pdf-templates/{$template->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('pdf_templates', ['id' => $template->id]);
    }

    public function testDestroyReturns423WhenLocked(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateDelete);

        $template = $this->createTemplate();
        $otherUser = User::factory()->create();

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $otherUser->id, 'editing', $otherUser->name);

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/admin/pdf-templates/{$template->id}");

        $response->assertStatus(423);
        $this->assertDatabaseHas('pdf_templates', [
            'id' => $template->id,
            'deleted_at' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // Save Fields
    // ---------------------------------------------------------------

    public function testSaveFieldsReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}/fields", [
            'fields' => [$this->makeFieldData()],
        ]);

        $response->assertStatus(403);
    }

    public function testSaveFieldsCreatesAndReturnsFields(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();

        $fields = [
            $this->makeFieldData(['x' => 10, 'y' => 20]),
            $this->makeFieldData(['x' => 50, 'y' => 60, 'data_source_key' => 'user.name']),
        ];

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}/fields", [
            'fields' => $fields,
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.fields'));
        $this->assertDatabaseCount('pdf_template_fields', 2);
    }

    public function testSaveFieldsReplacesExistingFields(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();

        // Create initial fields
        PdfTemplateField::query()->create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 5,
            'y' => 5,
            'width' => 10,
            'height' => 10,
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
        $this->assertDatabaseCount('pdf_template_fields', 1);

        // Save new fields (should replace)
        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}/fields", [
            'fields' => [
                $this->makeFieldData(['x' => 20, 'y' => 30]),
                $this->makeFieldData(['x' => 40, 'y' => 50]),
                $this->makeFieldData(['x' => 60, 'y' => 70]),
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('pdf_template_fields', 3);
    }

    public function testSaveFieldsValidatesDataSourceKey(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}/fields", [
            'fields' => [$this->makeFieldData(['data_source_key' => 'invalid.key'])],
        ]);

        $response->assertStatus(422);
    }

    public function testSaveFieldsValidatesCoordinates(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}/fields", [
            'fields' => [$this->makeFieldData(['x' => 150])],
        ]);

        $response->assertStatus(422);
    }

    public function testSaveFieldsWithShapeFillMode(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}/fields", [
            'fields' => [
                $this->makeFieldData([
                    'fill_mode' => 'shape',
                    'text_config' => null,
                    'shape_config' => [
                        'fill_style' => 'filled',
                        'color' => '#FF0000',
                        'stroke_width' => 1,
                    ],
                ]),
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('pdf_template_fields', 1);
    }

    public function testSaveFieldsReturns423WhenLocked(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();
        $otherUser = User::factory()->create();

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $otherUser->id, 'editing', $otherUser->name);

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/pdf-templates/{$template->id}/fields", [
            'fields' => [$this->makeFieldData()],
        ]);

        $response->assertStatus(423);
    }

    // ---------------------------------------------------------------
    // Re-upload
    // ---------------------------------------------------------------

    public function testReUploadReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/pdf-templates/{$template->id}/re-upload");

        $response->assertStatus(403);
    }

    public function testReUploadReplacesFileAndPreservesFields(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();
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

        $file = $this->createTestPdfUpload();

        $response = $this->actingAs($this->admin, 'api')->post("/admin/pdf-templates/{$template->id}/re-upload", [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('pdf_template_fields', 1);

        $template->refresh();
        $this->assertNotEquals('pdf-templates/test.pdf', $template->file_path);
    }

    public function testReUploadReturns423WhenLocked(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();
        $otherUser = User::factory()->create();

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $otherUser->id, 'editing', $otherUser->name);

        $file = $this->createTestPdfUpload();

        $response = $this->actingAs($this->admin, 'api')->post("/admin/pdf-templates/{$template->id}/re-upload", [
            'file' => $file,
        ]);

        $response->assertStatus(423);
    }

    // ---------------------------------------------------------------
    // Data Sources
    // ---------------------------------------------------------------

    public function testDataSourcesReturns403WithoutPermission(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/pdf-templates/data-sources');

        $response->assertStatus(403);
    }

    public function testDataSourcesReturnsList(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/pdf-templates/data-sources');

        $response->assertStatus(200)->assertJsonStructure([
            'data' => [
                '*' => ['key', 'name', 'placeholder', 'type', 'group'],
            ],
        ]);

        $this->assertNotEmpty($response->json('data'));
    }

    // ---------------------------------------------------------------
    // Lock
    // ---------------------------------------------------------------

    public function testLockReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/pdf-templates/{$template->id}/lock", [
            'reason' => 'editing',
        ]);

        $response->assertStatus(403);
    }

    public function testLockAcquiresLock(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/pdf-templates/{$template->id}/lock", [
            'reason' => 'editing',
        ]);

        $response->assertStatus(200);

        $lockService = app(PdfTemplateLockService::class);
        $this->assertTrue($lockService->isLockedBy($template->id, $this->admin->id));
    }

    public function testLockReturns423WhenLockedByAnother(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();
        $otherUser = User::factory()->create();

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $otherUser->id, 'editing', $otherUser->name);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/pdf-templates/{$template->id}/lock", [
            'reason' => 'editing',
        ]);

        $response->assertStatus(423);
    }

    public function testLockRefreshesIfAlreadyLockedBySameUser(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $this->admin->id, 'editing', $this->admin->name);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/pdf-templates/{$template->id}/lock", [
            'reason' => 'editing',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($lockService->isLockedBy($template->id, $this->admin->id));
    }

    public function testLockRejectsInvalidReason(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/pdf-templates/{$template->id}/lock", [
            'reason' => 'exporting',
        ]);

        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // Refresh Lock
    // ---------------------------------------------------------------

    public function testRefreshLockExtendsTtl(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $this->admin->id, 'editing', $this->admin->name);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/pdf-templates/{$template->id}/refresh-lock");

        $response->assertStatus(200);
        $this->assertTrue($lockService->isLockedBy($template->id, $this->admin->id));
    }

    public function testRefreshLockReturns423ForNonOwner(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();
        $otherUser = User::factory()->create();

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $otherUser->id, 'editing', $otherUser->name);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/pdf-templates/{$template->id}/refresh-lock");

        $response->assertStatus(423);
    }

    // ---------------------------------------------------------------
    // Unlock
    // ---------------------------------------------------------------

    public function testUnlockReleasesLock(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateUpdate);

        $template = $this->createTemplate();

        $lockService = app(PdfTemplateLockService::class);
        $lockService->acquire($template->id, $this->admin->id, 'editing', $this->admin->name);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/pdf-templates/{$template->id}/unlock");

        $response->assertStatus(200);
        $this->assertFalse($lockService->isLocked($template->id));
    }

    // ---------------------------------------------------------------
    // File download
    // ---------------------------------------------------------------

    public function testFileReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/pdf-templates/{$template->id}/file");

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // Lock info in responses
    // ---------------------------------------------------------------

    public function testLockInfoPresentInListResponse(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $template = $this->createTemplate();

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/pdf-templates');

        $response->assertStatus(200);
        // lock_info key is present in the response (appended attribute)
        $this->assertArrayHasKey('lock_info', $response->json('data.data.0'));
        $this->assertNull($response->json('data.data.0.lock_info'));
    }

    public function testLockInfoReflectsActiveLockInListAndDetailResponses(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $lockedTemplate = $this->createTemplate(['name' => 'Locked']);
        $freeTemplate = $this->createTemplate(['name' => 'Free']);
        $locker = User::factory()->create();
        app(PdfTemplateLockService::class)->acquire($lockedTemplate->id, $locker->id, 'editing', 'Locker Name');

        $list = $this->actingAs($this->admin, 'api')->getJson('/admin/pdf-templates')->assertStatus(200);
        $byId = collect($list->json('data.data'))->keyBy('id');

        $this->assertSame($locker->id, $byId[$lockedTemplate->id]['lock_info']['user_id']);
        $this->assertSame('editing', $byId[$lockedTemplate->id]['lock_info']['reason']);
        $this->assertSame('Locker Name', $byId[$lockedTemplate->id]['lock_info']['user_name']);
        $this->assertNull($byId[$freeTemplate->id]['lock_info']);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/pdf-templates/{$lockedTemplate->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.lock_info.user_id', $locker->id);
    }
}
