<?php

namespace Tests\Feature\Admin;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportBatchType;
use App\Enums\PermissionName;
use App\Models\ImportBatch;
use App\Models\Permission;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShiftOrderWorkbook;
use Tests\TestCase;

class ImportHistoryControllerTest extends TestCase
{
    use BuildsShiftOrderWorkbook, RefreshDatabase;

    private const OCTOBER_2026_SERIAL = 46296;

    private User $manager;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 10:00:00');
        Storage::fake();

        $this->manager = User::factory()->create();
        $this->manager->givePermission($this->permission(), null);

        $this->workplace = Workplace::create([
            'name' => 'Musashino',
            'address' => 'Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Position A']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testIndexRequiresTheImportOrderPermission(): void
    {
        /** @var User $outsider */
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'api')->getJson('/admin/import-history')->assertStatus(403);
    }

    public function testIndexListsConfirmedImportsWithFileAndImporter(): void
    {
        $batchId = $this->runImport();

        $response = $this->actingAs($this->manager, 'api')->getJson('/admin/import-history');

        $response->assertOk();
        $response->assertJsonPath('data.data.0.id', $batchId);
        $response->assertJsonPath('data.data.0.type', ImportBatchType::ShiftOrder->value);
        $response->assertJsonPath('data.data.0.status', ImportBatchStatus::Completed->value);
        $response->assertJsonPath('data.data.0.file_name', 'shift-order.xlsx');
        $response->assertJsonPath('data.data.0.created_count', 2);
        $response->assertJsonPath('data.data.0.importer.id', $this->manager->id);
        $this->assertArrayNotHasKey('meta', $response->json('data.data.0'));
    }

    public function testIndexOnlyShowsOwnImportsToAWorkplaceScopedManager(): void
    {
        $otherBatchId = $this->runImport();
        $scoped = $this->createWorkplaceScopedManager();
        $ownBatchId = $this->runImport($scoped);

        $response = $this->actingAs($scoped, 'api')->getJson('/admin/import-history');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $ownBatchId);

        $this->actingAs($this->manager, 'api')
            ->getJson('/admin/import-history')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
        $this->assertNotSame($ownBatchId, $otherBatchId);
    }

    public function testAWorkplaceScopedManagerCannotOpenOrDownloadSomeoneElsesImport(): void
    {
        $batchId = $this->runImport();
        $scoped = $this->createWorkplaceScopedManager();

        $this->actingAs($scoped, 'api')
            ->getJson("/admin/import-history/{$batchId}")
            ->assertStatus(404);
        $this->actingAs($scoped, 'api')
            ->getJson("/admin/import-history/{$batchId}/download")
            ->assertStatus(404);
    }

    public function testAWorkplaceScopedManagerCanOpenAndDownloadTheirOwnImport(): void
    {
        $scoped = $this->createWorkplaceScopedManager();
        $batchId = $this->runImport($scoped);

        $this->actingAs($scoped, 'api')
            ->getJson("/admin/import-history/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.batch.id', $batchId);
        $this->actingAs($scoped, 'api')
            ->getJson("/admin/import-history/{$batchId}/download")
            ->assertOk();
    }

    public function testIndexHidesBatchesStillWaitingForConfirmation(): void
    {
        $this->preview();

        $response = $this->actingAs($this->manager, 'api')->getJson('/admin/import-history');

        $response->assertOk();
        $response->assertJsonCount(0, 'data.data');
    }

    public function testIndexFiltersByType(): void
    {
        $this->runImport();

        $this->actingAs($this->manager, 'api')
            ->getJson('/admin/import-history?type=' . ImportBatchType::ShiftOrder->value)
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->actingAs($this->manager, 'api')->getJson('/admin/import-history?type=unknown')->assertStatus(422);
    }

    public function testShowReturnsFileAndWorkplaceSummary(): void
    {
        $batchId = $this->runImport();

        $response = $this->actingAs($this->manager, 'api')->getJson("/admin/import-history/{$batchId}");

        $response->assertOk();
        $response->assertJsonPath('data.batch.file_name', 'shift-order.xlsx');
        $response->assertJsonPath('data.batch.importer.id', $this->manager->id);
        $response->assertJsonPath('data.month', '2026-10');
        $response->assertJsonPath('data.sheet_name', '2026.10');
        $response->assertJsonPath('data.workplaces.0.workplace_name', 'Musashino');
        $response->assertJsonPath('data.workplaces.0.applied_count', 2);
    }

    public function testShowHidesAPendingBatch(): void
    {
        $batchId = $this->preview();

        $this->actingAs($this->manager, 'api')
            ->getJson("/admin/import-history/{$batchId}")
            ->assertStatus(404);
    }

    public function testDownloadReturnsALinkToTheStoredFile(): void
    {
        $batchId = $this->runImport();

        $response = $this->actingAs($this->manager, 'api')->getJson("/admin/import-history/{$batchId}/download");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.download_url'));
    }

    public function testDownloadFailsWhenTheStoredFileIsGone(): void
    {
        $batchId = $this->runImport();
        Storage::delete(ImportBatch::query()->findOrFail($batchId)->file_path);

        $this->actingAs($this->manager, 'api')
            ->getJson("/admin/import-history/{$batchId}/download")
            ->assertStatus(404);
    }

    private function permission(): Permission
    {
        return Permission::query()->where('name', PermissionName::ShiftImportOrder->value)->firstOrFail();
    }

    private function preview(?User $user = null): int
    {
        $response = $this->actingAs($user ?? $this->manager, 'api')->post('/manage/shifts/import-order/preview', [
            'file' => $this->workbook($this->rows()),
        ]);
        $response->assertOk();

        return (int) $response->json('data.batch.id');
    }

    private function runImport(?User $user = null): int
    {
        $importer = $user ?? $this->manager;
        $batchId = $this->preview($importer);
        $this->actingAs($importer, 'api')
            ->postJson("/manage/shifts/import-order/{$batchId}/confirm", ['decisions' => []])
            ->assertOk();

        return $batchId;
    }

    private function createWorkplaceScopedManager(): User
    {
        /** @var User $scoped */
        $scoped = User::factory()->create();
        $scoped->givePermission($this->permission(), $this->workplace);

        return $scoped;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function rows(): array
    {
        return [
            [null, null, 'ID', self::OCTOBER_2026_SERIAL, '1日', '2日', '3日'],
            [null, '合計Key', null, null, '木', '金', '土'],
            [null, null, $this->workplace->id, 'Musashino', '1日', '2日', '3日'],
            [null, null, null, null, '木', '金', '土'],
            [null, null, null, 'Position A', 3, 0, 1],
            [null, null, null, 'spot', 2, 0, 0],
            [null, null, null, '22:00〜30:00', 5, 0, 1],
            [null, null, null, null, null, null, null],
            [null, '注文合計(人)', null, null, 5, 0, 1],
        ];
    }
}
