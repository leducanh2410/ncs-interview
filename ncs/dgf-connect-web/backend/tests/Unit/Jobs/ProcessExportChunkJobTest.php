<?php

namespace Tests\Unit\Jobs;

use App\Actions\Admin\PdfTemplate\GeneratePdfAction;
use App\Enums\ExportBatchItemStatus;
use App\Enums\ExportBatchStatus;
use App\Jobs\ProcessExportChunkJob;
use App\Models\ExportBatch;
use App\Models\ExportBatchItem;
use App\Models\PdfTemplate;
use App\Models\PdfTemplateField;
use App\Models\User;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessExportChunkJobTest extends TestCase
{
    use RefreshDatabase;

    private $generatePdf;

    private $lockService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Log::spy();
        Queue::fake();

        $this->generatePdf = Mockery::mock(GeneratePdfAction::class);
        $this->lockService = Mockery::mock(PdfTemplateLockService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createBatchWithItems(int $itemCount = 3, string $status = 'pending'): ExportBatch
    {
        $template = PdfTemplate::create([
            'name' => 'Test Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        $exporter = User::factory()->create();

        $batch = ExportBatch::create([
            'pdf_template_id' => $template->id,
            'exported_by' => $exporter->id,
            'status' => $status,
        ]);

        for ($i = 0; $i < $itemCount; $i++) {
            $user = User::factory()->create();
            ExportBatchItem::create([
                'export_batch_id' => $batch->id,
                'user_id' => $user->id,
                'status' => ExportBatchItemStatus::Pending,
            ]);
        }

        return $batch;
    }

    public function testUpdatesBatchStatusToProcessing(): void
    {
        $batch = $this->createBatchWithItems(2);

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->lockService->shouldReceive('release')->andReturn(true);
        $this->generatePdf->shouldReceive('__invoke')->andReturn('%PDF-fake-content');

        $job = new ProcessExportChunkJob($batch->id);
        $job->handle($this->generatePdf, $this->lockService);

        $batch->refresh();
        $this->assertNotEquals(ExportBatchStatus::Pending, $batch->status);
    }

    public function testProcessesItemsAndUpdatesStatusToCompleted(): void
    {
        $batch = $this->createBatchWithItems(2);

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->lockService->shouldReceive('release')->andReturn(true);
        $this->generatePdf->shouldReceive('__invoke')->andReturn('%PDF-fake-content');

        $job = new ProcessExportChunkJob($batch->id, 0, 50);
        $job->handle($this->generatePdf, $this->lockService);

        $items = ExportBatchItem::where('export_batch_id', $batch->id)->get();
        foreach ($items as $item) {
            $this->assertEquals(ExportBatchItemStatus::Completed, $item->status);
            $this->assertNotNull($item->file_path);
            $this->assertNotNull($item->file_name);
        }
    }

    public function testStoresPdfOnStorage(): void
    {
        $batch = $this->createBatchWithItems(1);

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->lockService->shouldReceive('release')->andReturn(true);
        $this->generatePdf->shouldReceive('__invoke')->andReturn('%PDF-fake-content');

        $job = new ProcessExportChunkJob($batch->id);
        $job->handle($this->generatePdf, $this->lockService);

        $item = ExportBatchItem::where('export_batch_id', $batch->id)->first();
        Storage::assertExists($item->file_path);
    }

    public function testHandlesItemFailureGracefully(): void
    {
        $batch = $this->createBatchWithItems(3);

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->lockService->shouldReceive('release')->andReturn(true);

        // First item succeeds, second fails, third succeeds
        $callCount = 0;
        $this->generatePdf->shouldReceive('__invoke')->andReturnUsing(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 2) {
                throw new Exception('PDF generation failed for this user');
            }

            return '%PDF-fake-content';
        });

        $job = new ProcessExportChunkJob($batch->id, 0, 50);
        $job->handle($this->generatePdf, $this->lockService);

        $items = ExportBatchItem::where('export_batch_id', $batch->id)->orderBy('id')->get();

        $this->assertEquals(ExportBatchItemStatus::Completed, $items[0]->status);
        $this->assertEquals(ExportBatchItemStatus::Failed, $items[1]->status);
        $this->assertEquals('PDF generation failed for this user', $items[1]->error_message);
        $this->assertEquals(ExportBatchItemStatus::Completed, $items[2]->status);
    }

    public function testDispatchesNextChunkWhenItemsRemain(): void
    {
        $batch = $this->createBatchWithItems(5);

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->generatePdf->shouldReceive('__invoke')->andReturn('%PDF-fake-content');

        // Process with chunk size of 2 - should dispatch next chunk
        $job = new ProcessExportChunkJob($batch->id, 0, 2);
        $job->handle($this->generatePdf, $this->lockService);

        Queue::assertPushed(ProcessExportChunkJob::class);
    }

    public function testFinalizesBatchWhenAllItemsProcessed(): void
    {
        $batch = $this->createBatchWithItems(2);

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->lockService
            ->shouldReceive('release')
            ->with($batch->pdf_template_id, $batch->exported_by)
            ->andReturn(true);
        $this->generatePdf->shouldReceive('__invoke')->andReturn('%PDF-fake-content');

        $job = new ProcessExportChunkJob($batch->id, 0, 50);
        $job->handle($this->generatePdf, $this->lockService);

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
        $this->assertCount(1, $batch->zip_file_paths);
        Storage::assertExists($batch->zip_file_paths[0]);
    }

    public function testSplitsLargeBatchIntoMultipleZipFiles(): void
    {
        config()->set('pdf_export.max_zip_file_count', 2);
        config()->set('pdf_export.max_zip_size_bytes', 1024 * 1024);

        $batch = $this->createBatchWithItems(5);

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->lockService->shouldReceive('release')->andReturn(true);
        $this->generatePdf->shouldReceive('__invoke')->andReturn('%PDF-fake-content');

        $job = new ProcessExportChunkJob($batch->id, 0, 50);
        $job->handle($this->generatePdf, $this->lockService);

        $batch->refresh();

        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
        $this->assertCount(3, $batch->zip_file_paths);

        foreach ($batch->zip_file_paths as $zipFilePath) {
            Storage::assertExists($zipFilePath);
        }
    }

    public function testBatchStatusIsFailedWhenAllItemsFail(): void
    {
        $batch = $this->createBatchWithItems(2);

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->lockService->shouldReceive('release')->andReturn(true);
        $this->generatePdf->shouldReceive('__invoke')->andThrow(new Exception('Generation failed'));

        $job = new ProcessExportChunkJob($batch->id, 0, 50);
        $job->handle($this->generatePdf, $this->lockService);

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Failed, $batch->status);
    }

    public function testBatchStatusIsCompletedWhenSomeItemsFail(): void
    {
        $batch = $this->createBatchWithItems(2);

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->lockService->shouldReceive('release')->andReturn(true);

        $callCount = 0;
        $this->generatePdf->shouldReceive('__invoke')->andReturnUsing(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new Exception('Failed');
            }

            return '%PDF-fake-content';
        });

        $job = new ProcessExportChunkJob($batch->id, 0, 50);
        $job->handle($this->generatePdf, $this->lockService);

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
    }

    public function testReleasesLockOnBatchCompletion(): void
    {
        $batch = $this->createBatchWithItems(1);

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->lockService
            ->shouldReceive('release')
            ->with($batch->pdf_template_id, $batch->exported_by)
            ->once()
            ->andReturn(true);
        $this->generatePdf->shouldReceive('__invoke')->andReturn('%PDF-fake-content');

        $job = new ProcessExportChunkJob($batch->id, 0, 50);
        $job->handle($this->generatePdf, $this->lockService);

        // Verify batch completed and lock was released (Mockery verifies the 'once' expectation)
        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
    }

    public function testHandlesUserNotFound(): void
    {
        $batch = $this->createBatchWithItems(1);
        $item = ExportBatchItem::where('export_batch_id', $batch->id)->first();

        // Delete the user's info first (to avoid FK issues), then delete the user
        $user = User::find($item->user_id);
        $user->info()->delete();

        // Temporarily disable FK checks to simulate orphaned record
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $user->forceDelete();
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->lockService->shouldReceive('refresh')->andReturn(true);
        $this->lockService->shouldReceive('release')->andReturn(true);

        $job = new ProcessExportChunkJob($batch->id);
        $job->handle($this->generatePdf, $this->lockService);

        $item->refresh();
        $this->assertEquals(ExportBatchItemStatus::Failed, $item->status);
        $this->assertEquals('User not found', $item->error_message);
    }

    public function testHandlesBatchNotFound(): void
    {
        $job = new ProcessExportChunkJob(999999);
        $job->handle($this->generatePdf, $this->lockService);

        // Should just log and return without error
        Log::shouldHaveReceived('error')->once();
        // No items should have been processed
        $this->assertEquals(0, ExportBatchItem::count());
    }

    public function testFailedMethodMarksRemainingItemsAsFailed(): void
    {
        $batch = $this->createBatchWithItems(3);

        // Mark one item as completed to simulate partial progress
        $items = ExportBatchItem::where('export_batch_id', $batch->id)->orderBy('id')->get();
        $items[0]->update([
            'status' => ExportBatchItemStatus::Completed,
            'file_path' => 'pdf/test.pdf',
            'file_name' => 'test.pdf',
        ]);

        $this->app->instance(PdfTemplateLockService::class, $this->lockService);
        $this->lockService->shouldReceive('release')->andReturn(true);

        $exception = new Exception('Worker crashed');
        $job = new ProcessExportChunkJob($batch->id);
        $job->failed($exception);

        // Remaining pending items should be failed
        $pendingItems = ExportBatchItem::where('export_batch_id', $batch->id)->where('id', '!=', $items[0]->id)->get();

        foreach ($pendingItems as $item) {
            $this->assertEquals(ExportBatchItemStatus::Failed, $item->status);
            $this->assertStringContains('Worker crashed', $item->error_message);
        }

        // Batch should be "completed" (not "failed") because one item succeeded
        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
    }

    public function testFailedMethodSetsBatchToFailedWhenNoItemsCompleted(): void
    {
        $batch = $this->createBatchWithItems(2);

        $this->app->instance(PdfTemplateLockService::class, $this->lockService);
        $this->lockService->shouldReceive('release')->andReturn(true);

        $exception = new Exception('Worker crashed');
        $job = new ProcessExportChunkJob($batch->id);
        $job->failed($exception);

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Failed, $batch->status);
    }

    public function testFailedMethodReleasesLock(): void
    {
        $batch = $this->createBatchWithItems(1);

        $this->app->instance(PdfTemplateLockService::class, $this->lockService);
        $this->lockService
            ->shouldReceive('release')
            ->with($batch->pdf_template_id, $batch->exported_by)
            ->once()
            ->andReturn(true);

        $job = new ProcessExportChunkJob($batch->id);
        $job->failed(new Exception('Crash'));

        // Verify batch status was updated
        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Failed, $batch->status);
    }

    public function testSkipsProcessingWhenNoItemsRemain(): void
    {
        $batch = $this->createBatchWithItems(1);

        // Put a file in storage for the completed item
        Storage::put('pdf/test.pdf', '%PDF-fake-content');

        // Mark the only item as completed already
        ExportBatchItem::where('export_batch_id', $batch->id)->update([
            'status' => ExportBatchItemStatus::Completed,
            'file_path' => 'pdf/test.pdf',
            'file_name' => 'test.pdf',
        ]);

        $this->lockService->shouldReceive('release')->andReturn(true);
        $this->generatePdf->shouldNotReceive('__invoke');

        $job = new ProcessExportChunkJob($batch->id);
        $job->handle($this->generatePdf, $this->lockService);

        // Should finalize without generating any PDFs
        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
    }

    /**
     * Custom assertion for string contains.
     */
    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertNotNull($haystack);
        $this->assertStringContainsString($needle, $haystack);
    }
}
