<?php

namespace Tests\Feature\Manage\Reconciliation;

use App\Actions\Manage\Reconciliation\ReparseLegacyAmazonReconciliationBatchAction;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAmazonKadouWorkbook;
use Tests\Concerns\SeedsReconciliationFixtures;
use Tests\TestCase;

class LegacyAmazonReconciliationBatchTest extends TestCase
{
    use BuildsAmazonKadouWorkbook;
    use RefreshDatabase;
    use SeedsReconciliationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    public function testMigrationReparsesLegacyDraftFromOriginalFileAndResetsDecisions(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $seeded = $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);
        $batch = $this->upload($admin);
        $legacyRowId = $this->makeLegacy($batch['id']);
        ReconciliationRow::query()
            ->whereKey($legacyRowId)
            ->update([
                'decision_check_in' => 'amazon',
                'decision_check_out' => 'amazon',
            ]);

        $this->runReparseMigration();

        $rows = ReconciliationRow::query()->where('batch_id', $batch['id'])->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertNotSame($legacyRowId, $row->id);
        $this->assertSame('2026-08-25 21:00:00', $row->external_check_in->toDateTimeString());
        $this->assertSame('2026-08-26 06:00:00', $row->external_check_out->toDateTimeString());
        $this->assertArrayHasKey('summary', $row->raw);
        $this->assertArrayNotHasKey('scheduled_range', $row->raw);
        $this->assertSame($seeded['employee']->id, $row->user_id);
        $this->assertSame('matched', $row->compare_status);
        $this->assertSame('dgf', $row->decision_check_in);
        $this->assertSame('dgf', $row->decision_check_out);
        $this->assertNotNull($row->snapshot_dgf_paid);
        $this->assertSame(1, ReconciliationBatch::query()->findOrFail($batch['id'])->matched_count);
    }

    public function testDraftAlreadyOnPaidTimeFormatIsLeftUntouched(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);
        $batch = $this->upload($admin);
        $rowId = $batch['rows'][0]['id'];
        ReconciliationRow::query()
            ->whereKey($rowId)
            ->update(['decision_check_in' => 'amazon']);

        $result = app(ReparseLegacyAmazonReconciliationBatchAction::class)(
            ReconciliationBatch::query()->findOrFail($batch['id'])
        );

        $this->assertSame(ReparseLegacyAmazonReconciliationBatchAction::RESULT_SKIPPED, $result);
        $this->assertSame('amazon', ReconciliationRow::query()->findOrFail($rowId)->decision_check_in);
    }

    public function testConfirmedLegacyBatchKeepsItsPunchInterpretation(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);
        $batch = $this->upload($admin);
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();
        $legacyRowId = $this->makeLegacy($batch['id']);

        $this->runReparseMigration();

        $row = ReconciliationRow::query()->findOrFail($legacyRowId);
        $this->assertSame('2026-08-25 20:10:00', $row->external_check_in->toDateTimeString());
        $this->assertArrayHasKey('scheduled_range', $row->raw);

        $shown = $this->actingAs($admin, 'api')
            ->getJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk()
            ->json('data.rows.0');
        $this->assertSame('punch', $shown['time_basis']);
        $this->assertSame('2026-08-25 20:10:00', $shown['external_check_in']);
        $this->assertNull($shown['external_summary']);
        $this->assertNull($shown['dgf_summary']);
    }

    public function testRowsParsedFromPaidTimeAreServedWithPaidBasis(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);
        $batch = $this->upload($admin);

        $this->assertSame('paid', $batch['rows'][0]['time_basis']);
        $this->assertSame('2026-08-25 21:00:00', $batch['rows'][0]['external_check_in']);
    }

    public function testLegacyDraftWhoseOriginalFileIsGoneIsDeleted(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $batch = $this->upload($admin);
        $this->makeLegacy($batch['id']);
        $stored = ReconciliationBatch::query()->findOrFail($batch['id']);
        Storage::delete($stored->original_file_path);

        $result = app(ReparseLegacyAmazonReconciliationBatchAction::class)($stored);

        $this->assertSame(ReparseLegacyAmazonReconciliationBatchAction::RESULT_DELETED, $result);
        $this->assertNull(ReconciliationBatch::query()->find($batch['id']));
        $this->assertSame(0, ReconciliationRow::query()->where('batch_id', $batch['id'])->count());
    }

    public function testLegacyDraftWhoseOriginalFileNoLongerParsesIsDeletedWithItsFile(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $batch = $this->upload($admin);
        $this->makeLegacy($batch['id']);
        $stored = ReconciliationBatch::query()->findOrFail($batch['id']);
        Storage::put($stored->original_file_path, 'not a workbook');

        $result = app(ReparseLegacyAmazonReconciliationBatchAction::class)($stored);

        $this->assertSame(ReparseLegacyAmazonReconciliationBatchAction::RESULT_DELETED, $result);
        $this->assertNull(ReconciliationBatch::query()->find($batch['id']));
        Storage::assertMissing($stored->original_file_path);
    }

    private function makeLegacy(int $batchId): int
    {
        $row = ReconciliationRow::query()->where('batch_id', $batchId)->firstOrFail();
        $row->external_check_in = '2026-08-25 20:10:00';
        $row->external_check_out = '2026-08-26 06:05:00';
        $row->raw = [
            'scheduled_range' => '21:00-06:00',
            'work_time_range' => '21:00-06:00',
            'source_row' => $row->raw['source_row'],
        ];
        $row->snapshot_dgf_paid = null;
        $row->save();

        return $row->id;
    }

    private function runReparseMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_09_23_000002_reparse_legacy_amazon_reconciliation_batches.php'
        );
        $migration->up();
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(User $admin): array
    {
        $path = sys_get_temp_dir() . '/recon-' . uniqid() . '.xlsx';
        $this->writeAmazonKadouFile($path, [
            [
                'name' => 'パテル バルジュクマル',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:10:00',
                'punch_out' => '2026-08-26 06:05:00',
                'work_in' => '2026-08-25 21:00:00',
                'work_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
            ],
        ]);
        $created = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, 'amazon.xlsx', null, null, true),
        ]);
        $created->assertCreated();
        @unlink($path);

        return $created->json('data');
    }
}
