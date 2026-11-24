<?php

namespace Tests\Feature\Manage\ShiftOrderImport;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportBatchType;
use App\Jobs\DeleteStalePendingImportBatches;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteStalePendingImportBatchesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 10:00:00');
        Storage::fake();

        $this->manager = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testDeletesAbandonedPreviewsWithTheirUploadedFile(): void
    {
        $stale = $this->createBatch(ImportBatchStatus::Pending, '2026-09-18 09:00:00');

        (new DeleteStalePendingImportBatches())->handle();

        $this->assertDatabaseMissing('import_batches', ['id' => $stale->id]);
        Storage::assertMissing($stale->file_path);
    }

    public function testKeepsPreviewsThatAreStillFresh(): void
    {
        $fresh = $this->createBatch(ImportBatchStatus::Pending, '2026-09-20 08:00:00');

        (new DeleteStalePendingImportBatches())->handle();

        $this->assertDatabaseHas('import_batches', ['id' => $fresh->id]);
        Storage::assertExists($fresh->file_path);
    }

    public function testKeepsConfirmedBatchesHoweverOldTheyAre(): void
    {
        $completed = $this->createBatch(ImportBatchStatus::Completed, '2026-01-01 00:00:00');

        (new DeleteStalePendingImportBatches())->handle();

        $this->assertDatabaseHas('import_batches', ['id' => $completed->id]);
        Storage::assertExists($completed->file_path);
    }

    private function createBatch(ImportBatchStatus $status, string $updatedAt): ImportBatch
    {
        $path = 'imports/shift-order/' . uniqid() . '.xlsx';
        Storage::put($path, 'workbook');

        /** @var ImportBatch $batch */
        $batch = ImportBatch::query()->create([
            'type' => ImportBatchType::ShiftOrder,
            'status' => $status,
            'imported_by' => $this->manager->id,
            'file_name' => 'shift-order.xlsx',
            'file_path' => $path,
            'meta' => ['plan' => []],
        ]);
        $batch->forceFill(['updated_at' => $updatedAt])->saveQuietly();

        return $batch;
    }
}
