<?php

namespace Tests\Feature\Manage\Reconciliation;

use App\Models\WorkHistoryEditRequest;
use App\Services\Reconciliation\AmazonKadouParser;
use App\Services\Reconciliation\ReconciliationParserRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\FakeTestReconciliationParser;
use Tests\Concerns\SeedsReconciliationFixtures;
use Tests\TestCase;

class FakeParserReconciliationBatchTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReconciliationFixtures;

    public function testFakeParserDrivesMatchAndExportWithoutAmazonTypes(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);

        $this->app->instance(
            ReconciliationParserRegistry::class,
            new ReconciliationParserRegistry([
                new AmazonKadouParser(),
                new FakeTestReconciliationParser(
                    'ウィジェシンヘ サミシカ',
                    '2026-08-25',
                    '2026-08-25 20:19:00',
                    '2026-08-26 06:03:00',
                    60
                ),
            ])
        );

        $path = sys_get_temp_dir() . '/fake-recon-' . uniqid() . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'not an amazon kadou workbook');
        (new Xlsx($spreadsheet))->save($path);

        $created = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'fake_test',
            'file' => new UploadedFile($path, 'fake.xlsx', null, null, true),
        ]);
        @unlink($path);

        $created->assertCreated();
        $created->assertJsonPath('data.source', 'fake_test');
        $created->assertJsonPath('data.rows.0.check_in_id', $seeded['checkIn']->id);
        $this->assertNotSame('amazon_kadou', $created->json('data.source'));

        $data = $created->json('data');
        foreach ($data['rows'] as $row) {
            if ($row['compare_status'] === 'differs') {
                $this->actingAs($admin, 'api')
                    ->patchJson("/manage/work-history-reconciliations/{$data['id']}/rows/{$row['id']}", [
                        'decisions' => [
                            'check_in_time' => 'amazon',
                            'check_out_time' => 'amazon',
                            'break_minutes' => 'dgf',
                        ],
                    ])
                    ->assertOk();
            }
        }

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$data['id']}/confirm")
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$data['id']}/export")
            ->assertOk();

        $this->assertSame(0, WorkHistoryEditRequest::query()->count());
        $this->assertSame($seeded['checkIn']->check_in_time, $seeded['checkIn']->fresh()->check_in_time);
    }
}
