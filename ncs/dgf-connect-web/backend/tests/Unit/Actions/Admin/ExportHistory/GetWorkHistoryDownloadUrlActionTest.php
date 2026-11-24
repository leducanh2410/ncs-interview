<?php

namespace Tests\Unit\Actions\Admin\ExportHistory;

use App\Actions\Admin\ExportHistory\GetWorkHistoryDownloadUrlAction;
use App\Enums\ErrorCode;
use App\Enums\ExportBatchStatus;
use App\Enums\ExportBatchType;
use App\Enums\PermissionName;
use App\Models\ExportBatch;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GetWorkHistoryDownloadUrlActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GetWorkHistoryDownloadUrlAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->grantPermission($this->user, PermissionName::WorkHistoryExport);

        $this->action = new GetWorkHistoryDownloadUrlAction();
    }

    public function testReturnsDownloadUrlForOwnCompletedWorkHistoryBatch(): void
    {
        $batch = $this->createWorkHistoryBatch($this->user);
        Storage::put('work-history-exports/1/report.zip', 'zip-content');
        $batch->update(['file_path' => 'work-history-exports/1/report.zip']);

        $result = ($this->action)($batch, $this->user);

        $this->assertNull($result->getError());
        $this->assertNotEmpty($result->getData()['download_url']);
    }

    public function testRejectsBatchThatIsNotWorkHistory(): void
    {
        $batch = ExportBatch::query()->create([
            'type' => ExportBatchType::Pdf,
            'exported_by' => $this->user->id,
            'status' => ExportBatchStatus::Completed,
            'file_path' => 'work-history-exports/1/report.zip',
        ]);
        Storage::put('work-history-exports/1/report.zip', 'zip-content');

        $result = ($this->action)($batch, $this->user);

        $this->assertSame(ErrorCode::NotFound, $result->getError());
        $this->assertSame(404, $result->getStatus());
    }

    public function testRejectsBatchWithoutWorkHistoryExportPermission(): void
    {
        $batch = $this->createWorkHistoryBatch($this->user);
        Storage::put('work-history-exports/1/report.zip', 'zip-content');
        $batch->update(['file_path' => 'work-history-exports/1/report.zip']);

        $otherUser = User::factory()->create();

        $result = ($this->action)($batch, $otherUser);

        $this->assertSame(ErrorCode::NotFound, $result->getError());
        $this->assertSame(404, $result->getStatus());
    }

    public function testRejectsBatchExportedByAnotherUser(): void
    {
        $otherUser = User::factory()->create();
        $this->grantPermission($otherUser, PermissionName::WorkHistoryExport);

        $batch = $this->createWorkHistoryBatch($otherUser);
        Storage::put('work-history-exports/1/report.zip', 'zip-content');
        $batch->update(['file_path' => 'work-history-exports/1/report.zip']);

        $result = ($this->action)($batch, $this->user);

        $this->assertSame(ErrorCode::NotFound, $result->getError());
        $this->assertSame(404, $result->getStatus());
    }

    public function testRejectsBatchThatIsNotCompleted(): void
    {
        $batch = $this->createWorkHistoryBatch($this->user);
        $batch->update(['status' => ExportBatchStatus::Processing, 'file_path' => null]);

        $result = ($this->action)($batch, $this->user);

        $this->assertSame(ErrorCode::NotFound, $result->getError());
        $this->assertSame(404, $result->getStatus());
    }

    public function testRejectsBatchWithoutFilePath(): void
    {
        $batch = $this->createWorkHistoryBatch($this->user);
        $batch->update(['file_path' => null]);

        $result = ($this->action)($batch, $this->user);

        $this->assertSame(ErrorCode::NotFound, $result->getError());
        $this->assertSame(404, $result->getStatus());
        $this->assertSame('Export file not available.', $result->getData()['message']);
    }

    public function testRejectsBatchWhenFileMissingOnStorage(): void
    {
        $batch = $this->createWorkHistoryBatch($this->user);
        $batch->update(['file_path' => 'work-history-exports/1/report.zip']);

        $result = ($this->action)($batch, $this->user);

        $this->assertSame(ErrorCode::NotFound, $result->getError());
        $this->assertSame(404, $result->getStatus());
        $this->assertSame('Export file not available.', $result->getData()['message']);
    }

    private function grantPermission(User $user, PermissionName $permission): void
    {
        $perm = Permission::firstOrCreate(['name' => $permission->value], ['display_name' => $permission->value]);
        $user->permissions()->syncWithoutDetaching([$perm->id]);
    }

    private function createWorkHistoryBatch(User $exporter): ExportBatch
    {
        return ExportBatch::query()->create([
            'type' => ExportBatchType::WorkHistory,
            'exported_by' => $exporter->id,
            'status' => ExportBatchStatus::Completed,
            'csv_total_count' => 3,
            'csv_completed_count' => 3,
            'csv_failed_count' => 0,
            'meta' => ['conditions' => ['dates' => ['2026-05-01', '2026-05-31']]],
        ]);
    }
}
