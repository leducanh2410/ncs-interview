<?php

namespace Tests\Unit\Services\PdfTemplate;

use App\Models\PdfTemplate;
use App\Models\PdfTemplateField;
use App\Services\Admin\PdfTemplate\DataSourceResolver;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use App\Services\Admin\PdfTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PdfTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private PdfTemplateService $service;

    private PdfTemplateLockService $lockService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->lockService = Mockery::mock(PdfTemplateLockService::class);
        $dataSourceResolver = new DataSourceResolver();

        $this->service = new PdfTemplateService($this->lockService, $dataSourceResolver);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testCreatesTemplateWithFileUpload(): void
    {
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        // Mock FPDI page count - since we can't create a real PDF, we'll test the DB record creation
        $template = $this->service->create(['name' => 'Test Template'], $file);

        $this->assertInstanceOf(PdfTemplate::class, $template);
        $this->assertEquals('Test Template', $template->name);
        $this->assertEquals('test.pdf', $template->file_name);
        $this->assertNotEmpty($template->file_path);
        Storage::assertExists($template->file_path);
    }

    public function testListsTemplatesWithPagination(): void
    {
        $this->lockService->shouldReceive('get')->andReturn(null);

        PdfTemplate::create([
            'name' => 'Template A',
            'file_path' => 'pdf-templates/a.pdf',
            'file_name' => 'a.pdf',
            'page_count' => 1,
        ]);
        PdfTemplate::create([
            'name' => 'Template B',
            'file_path' => 'pdf-templates/b.pdf',
            'file_name' => 'b.pdf',
            'page_count' => 2,
        ]);

        $result = $this->service->list([]);

        $this->assertEquals(2, $result->total());
    }

    public function testListsTemplatesFilteredByName(): void
    {
        $this->lockService->shouldReceive('get')->andReturn(null);

        PdfTemplate::create([
            'name' => 'Residence Card',
            'file_path' => 'pdf-templates/residence.pdf',
            'file_name' => 'residence.pdf',
            'page_count' => 1,
        ]);
        PdfTemplate::create([
            'name' => 'Contract',
            'file_path' => 'pdf-templates/contract.pdf',
            'file_name' => 'contract.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->list(['name' => 'Residence']);

        $this->assertEquals(1, $result->total());
        $this->assertEquals('Residence Card', $result->items()[0]->name);
    }

    public function testListExcludesSoftDeletedTemplates(): void
    {
        $this->lockService->shouldReceive('get')->andReturn(null);

        $template = PdfTemplate::create([
            'name' => 'Deleted Template',
            'file_path' => 'pdf-templates/deleted.pdf',
            'file_name' => 'deleted.pdf',
            'page_count' => 1,
        ]);
        $template->delete();

        PdfTemplate::create([
            'name' => 'Active Template',
            'file_path' => 'pdf-templates/active.pdf',
            'file_name' => 'active.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->list([]);

        $this->assertEquals(1, $result->total());
        $this->assertEquals('Active Template', $result->items()[0]->name);
    }

    public function testShowLoadsFieldsAndLockInfo(): void
    {
        $this->lockService->shouldReceive('get')->with(Mockery::any())->andReturn(null);

        $template = PdfTemplate::create([
            'name' => 'Show Template',
            'file_path' => 'pdf-templates/show.pdf',
            'file_name' => 'show.pdf',
            'page_count' => 1,
        ]);

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 20.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.email',
            'fill_mode' => 'text',
        ]);

        $result = $this->service->show($template);

        $this->assertTrue($result->relationLoaded('fields'));
        $this->assertCount(1, $result->fields);
    }

    public function testUpdatesTemplateName(): void
    {
        $template = PdfTemplate::create([
            'name' => 'Old Name',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->update($template, ['name' => 'New Name']);

        $this->assertEquals('New Name', $result->name);
        $this->assertEquals('New Name', $template->fresh()->name);
    }

    public function testSoftDeletesTemplate(): void
    {
        $template = PdfTemplate::create([
            'name' => 'To Delete',
            'file_path' => 'pdf-templates/delete.pdf',
            'file_name' => 'delete.pdf',
            'page_count' => 1,
        ]);

        $this->service->delete($template);

        $this->assertSoftDeleted('pdf_templates', ['id' => $template->id]);
    }

    public function testSyncsFieldsDeletesAndReinserts(): void
    {
        $this->lockService->shouldReceive('get')->andReturn(null);

        $template = PdfTemplate::create([
            'name' => 'Sync Template',
            'file_path' => 'pdf-templates/sync.pdf',
            'file_name' => 'sync.pdf',
            'page_count' => 1,
        ]);

        // Create initial field
        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 20.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.email',
            'fill_mode' => 'text',
        ]);

        // Sync with new fields
        $newFields = [
            [
                'page' => 1,
                'shape' => 'rectangle',
                'x' => 15.0,
                'y' => 25.0,
                'width' => 20.0,
                'height' => 10.0,
                'data_source_key' => 'user.name',
                'fill_mode' => 'text',
                'text_config' => ['font_weight' => 'bold', 'font_size' => 14],
            ],
            [
                'page' => 1,
                'shape' => 'circle',
                'x' => 50.0,
                'y' => 50.0,
                'width' => 5.0,
                'height' => 5.0,
                'data_source_key' => 'is_household_head',
                'fill_mode' => 'shape',
                'shape_config' => ['fill_style' => 'filled', 'color' => '#FF0000'],
            ],
        ];

        $result = $this->service->syncFields($template, $newFields);

        $this->assertCount(2, $result->fields);
        $this->assertEquals('user.name', $result->fields[0]->data_source_key);
    }

    public function testReUploadReplacesFileAndUpdatesMetadata(): void
    {
        Storage::put('pdf-templates/old.pdf', 'old content');

        $template = PdfTemplate::create([
            'name' => 'ReUpload Template',
            'file_path' => 'pdf-templates/old.pdf',
            'file_name' => 'old.pdf',
            'page_count' => 1,
        ]);

        // Create a field to verify it's preserved
        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 20.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.email',
            'fill_mode' => 'text',
        ]);

        $newFile = UploadedFile::fake()->create('new.pdf', 200, 'application/pdf');

        $result = $this->service->reUpload($template, $newFile);

        $this->assertEquals('new.pdf', $result->file_name);
        $this->assertNotEquals('pdf-templates/old.pdf', $result->file_path);
        Storage::assertExists($result->file_path);
        Storage::assertMissing('pdf-templates/old.pdf');

        // Verify fields are preserved
        $this->assertEquals(1, $template->fields()->count());
    }

    public function testGetDataSourcesReturnsConfig(): void
    {
        $sources = $this->service->getDataSources();

        $this->assertIsArray($sources);
        $this->assertNotEmpty($sources);

        $keys = array_column($sources, 'key');
        $this->assertContains('user.email', $keys);
    }

    public function testCheckLockReturnsNullWhenNotLocked(): void
    {
        $this->lockService->shouldReceive('get')->andReturn(null);

        $template = PdfTemplate::create([
            'name' => 'Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->checkLock($template, 10);
        $this->assertNull($result);
    }

    public function testCheckLockReturnsNullWhenLockedBySameUser(): void
    {
        $this->lockService->shouldReceive('get')->andReturn([
            'user_id' => 10,
            'reason' => 'editing',
            'user_name' => 'Admin',
            'locked_at' => now()->toIso8601String(),
            'ttl' => 1800,
        ]);

        $template = PdfTemplate::create([
            'name' => 'Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->checkLock($template, 10);
        $this->assertNull($result);
    }

    public function testCheckLockReturnsInfoWhenLockedByAnotherUser(): void
    {
        $lockInfo = [
            'user_id' => 20,
            'reason' => 'editing',
            'user_name' => 'Other Admin',
            'locked_at' => now()->toIso8601String(),
            'ttl' => 1800,
        ];
        $this->lockService->shouldReceive('get')->andReturn($lockInfo);

        $template = PdfTemplate::create([
            'name' => 'Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->checkLock($template, 10);
        $this->assertEquals($lockInfo, $result);
    }

    public function testAcquireLockWhenNotLocked(): void
    {
        $this->lockService->shouldReceive('get')->andReturn(null);
        $this->lockService->shouldReceive('acquire')->andReturn(true);

        $template = PdfTemplate::create([
            'name' => 'Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->acquireLock($template, 10, 'editing', 'Admin');
        $this->assertTrue($result);
    }

    public function testAcquireLockRefreshesWhenLockedBySameUser(): void
    {
        $this->lockService->shouldReceive('get')->andReturn([
            'user_id' => 10,
            'reason' => 'editing',
            'user_name' => 'Admin',
            'locked_at' => now()->toIso8601String(),
            'ttl' => 1800,
        ]);
        $this->lockService->shouldReceive('refresh')->with(Mockery::any(), 10)->andReturn(true);

        $template = PdfTemplate::create([
            'name' => 'Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->acquireLock($template, 10, 'editing', 'Admin');
        $this->assertTrue($result);
    }

    public function testAcquireLockReturnsInfoWhenLockedByAnotherUser(): void
    {
        $lockInfo = [
            'user_id' => 20,
            'reason' => 'editing',
            'user_name' => 'Other Admin',
            'locked_at' => now()->toIso8601String(),
            'ttl' => 1800,
        ];
        $this->lockService->shouldReceive('get')->andReturn($lockInfo);

        $template = PdfTemplate::create([
            'name' => 'Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->acquireLock($template, 10, 'editing', 'Admin');
        $this->assertEquals($lockInfo, $result);
    }

    public function testReleaseLockDelegatesToLockService(): void
    {
        $this->lockService->shouldReceive('release')->with(Mockery::any(), 10)->andReturn(true);

        $template = PdfTemplate::create([
            'name' => 'Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->releaseLock($template, 10);
        $this->assertTrue($result);
    }

    public function testRefreshLockWhenLockedBySameUser(): void
    {
        $this->lockService->shouldReceive('get')->andReturn([
            'user_id' => 10,
            'reason' => 'editing',
            'user_name' => 'Admin',
            'locked_at' => now()->toIso8601String(),
            'ttl' => 1800,
        ]);
        $this->lockService->shouldReceive('refresh')->with(Mockery::any(), 10)->andReturn(true);

        $template = PdfTemplate::create([
            'name' => 'Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->refreshLock($template, 10);
        $this->assertTrue($result);
    }

    public function testRefreshLockReturnsFalseWhenNotLocked(): void
    {
        $this->lockService->shouldReceive('get')->andReturn(null);

        $template = PdfTemplate::create([
            'name' => 'Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->refreshLock($template, 10);
        $this->assertFalse($result);
    }

    public function testRefreshLockReturnsFalseWhenLockedByAnotherUser(): void
    {
        $this->lockService->shouldReceive('get')->andReturn([
            'user_id' => 20,
            'reason' => 'editing',
            'user_name' => 'Other',
            'locked_at' => now()->toIso8601String(),
            'ttl' => 1800,
        ]);

        $template = PdfTemplate::create([
            'name' => 'Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $result = $this->service->refreshLock($template, 10);
        $this->assertFalse($result);
    }
}
