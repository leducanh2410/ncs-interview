<?php

namespace Tests\Feature\Manage\Reconciliation;

use App\Actions\Manage\Reconciliation\RecalculateReconciliationBatchCountsAction;
use App\Enums\ReconciliationBatchStatus;
use App\Models\ReconciliationBatch;
use App\Models\WorkHistoryEditRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\Concerns\BuildsAmazonKadouWorkbook;
use Tests\Concerns\SeedsReconciliationFixtures;
use Tests\TestCase;

class CreateReconciliationBatchTest extends TestCase
{
    use BuildsAmazonKadouWorkbook;
    use RefreshDatabase;
    use SeedsReconciliationFixtures;

    public function testHappyPathCreatesDraftWithRows(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $path = $this->tempWorkbook([
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $response = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, 'amazon.xlsx', null, null, true),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', ReconciliationBatchStatus::Draft->value);
        $this->assertSame($seeded['checkIn']->id, $response->json('data.rows.0.check_in_id'));
        $this->assertSame(0, WorkHistoryEditRequest::query()->count());
        $this->assertSame($seeded['checkIn']->check_in_time, $seeded['checkIn']->fresh()->check_in_time);
        @unlink($path);
    }

    public function testFormatErrorCreatesNoBatch(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $path = sys_get_temp_dir() . '/bad-' . uniqid() . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'nope');
        (new Xlsx($spreadsheet))->save($path);

        $before = ReconciliationBatch::query()->count();
        $response = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, 'bad.xlsx', null, null, true),
        ]);

        $response->assertStatus(422);
        $this->assertSame($before, ReconciliationBatch::query()->count());
        @unlink($path);
    }

    public function testUnresolvableFormulaIsRejectedAs422NotServerError(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $path = $this->tempWorkbook([
            [
                'name' => 'Formula Row',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getActiveSheet()->setCellValue('I6', '=SUM(');
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        // Reading 作業時間 through getCalculatedValue() evaluates the formula, so
        // the admin must get RECONCILIATION_INVALID_FORMAT naming the row rather
        // than a 500 carrying a PhpSpreadsheet message.
        $this->actingAs($admin, 'api')
            ->post('/manage/work-history-reconciliations', [
                'source' => 'amazon_kadou',
                'file' => new UploadedFile($path, 'formula.xlsx', null, null, true),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_INVALID_FORMAT')
            ->assertJsonPath('data.reason', 'invalid_formula')
            ->assertJsonPath('data.details.row', 6);
        $this->assertSame(0, ReconciliationBatch::query()->count());
        @unlink($path);
    }

    public function testZeroPunchesCreatesNoBatch(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $path = $this->tempWorkbook([['name' => 'No Punch', 'date' => '2026-08-25']]);

        $response = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, 'empty.xlsx', null, null, true),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ReconciliationBatch::query()->count());
        @unlink($path);
    }

    public function testTransactionFailureAfterFileStoredRollsBackAndDeletesFile(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $path = $this->tempWorkbook([
            [
                'name' => 'Someone',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        // RecalculateReconciliationBatchCountsAction runs last inside the
        // transaction, after the batch row, its rows and the original file are
        // all already persisted/stored. Forcing it to fail here exercises the
        // catch block's cleanup: the transaction must roll back the batch/rows
        // and the orphaned original file must be deleted, not left behind.
        $recalculateCounts = Mockery::mock(RecalculateReconciliationBatchCountsAction::class);
        $recalculateCounts->shouldReceive('__invoke')->andThrow(new RuntimeException('forced mid-transaction failure'));
        $this->app->instance(RecalculateReconciliationBatchCountsAction::class, $recalculateCounts);

        $response = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, 'a.xlsx', null, null, true),
        ]);

        $response->assertStatus(500);
        $this->assertSame(0, ReconciliationBatch::query()->count());
        $this->assertEmpty(Storage::allFiles());
        @unlink($path);
    }

    public function testDuplicateBytesCreateIndependentBatches(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $path = $this->tempWorkbook([
            [
                'name' => 'Someone',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->actingAs($admin, 'api')
            ->post('/manage/work-history-reconciliations', [
                'source' => 'amazon_kadou',
                'file' => new UploadedFile($path, 'a.xlsx', null, null, true),
            ])
            ->assertCreated();
        $this->actingAs($admin, 'api')
            ->post('/manage/work-history-reconciliations', [
                'source' => 'amazon_kadou',
                'file' => new UploadedFile($path, 'a.xlsx', null, null, true),
            ])
            ->assertCreated();

        $this->assertSame(2, ReconciliationBatch::query()->count());
        @unlink($path);
    }

    public function testDownloadsTheStoredOriginalFileUnderItsUploadedName(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $path = $this->tempWorkbook([
            [
                'name' => 'Someone',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $created = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, 'amazon-2026-08.xlsx', null, null, true),
        ]);
        $created->assertCreated();
        $id = $created->json('data.id');
        @unlink($path);

        // The stored upload is the audit artifact a disputed reconciliation is
        // re-checked against (Requirement 11.3), and the detail screen offers it
        // for download.
        $this->actingAs($admin, 'api')
            ->get("/manage/work-history-reconciliations/{$id}/original")
            ->assertOk()
            ->assertDownload('amazon-2026-08.xlsx');
    }

    public function testDownloadOriginalKeepsJapaneseFilenameInTheHeader(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $path = $this->tempWorkbook([
            [
                'name' => 'Someone',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $created = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, '稼働実績.xlsx', null, null, true),
        ]);
        $created->assertCreated();
        $id = $created->json('data.id');
        @unlink($path);

        // Amazon's files are named in Japanese; the ASCII `filename=` fallback
        // Symfony emits for them is empty, so the real name only survives in the
        // RFC 5987 `filename*=` parameter that browsers actually prefer.
        $disposition = $this->actingAs($admin, 'api')
            ->get("/manage/work-history-reconciliations/{$id}/original")
            ->assertOk()
            ->headers->get('content-disposition');
        $this->assertStringContainsString("filename*=utf-8''" . rawurlencode('稼働実績.xlsx'), $disposition);
    }

    public function testDownloadOriginalReturns404WhenTheStoredFileIsGone(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $path = $this->tempWorkbook([
            [
                'name' => 'Someone',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $created = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, 'amazon.xlsx', null, null, true),
        ]);
        $created->assertCreated();
        $id = $created->json('data.id');
        @unlink($path);

        Storage::delete(ReconciliationBatch::query()->findOrFail($id)->original_file_path);

        $this->actingAs($admin, 'api')
            ->get("/manage/work-history-reconciliations/{$id}/original")
            ->assertStatus(404);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function tempWorkbook(array $rows): string
    {
        $path = sys_get_temp_dir() . '/recon-' . uniqid() . '.xlsx';
        $this->writeAmazonKadouFile($path, $rows);

        return $path;
    }
}
