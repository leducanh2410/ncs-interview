<?php

namespace Tests\Feature\Manage\Reconciliation;

use App\Models\ReconciliationBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsAmazonKadouWorkbook;
use Tests\Concerns\BuildsStaffTimesheetWorkbooks;
use Tests\Concerns\SeedsReconciliationFixtures;
use Tests\TestCase;

class StaffNoReconciliationTest extends TestCase
{
    use BuildsAmazonKadouWorkbook;
    use BuildsStaffTimesheetWorkbooks;
    use RefreshDatabase;
    use SeedsReconciliationFixtures;

    public function testYamatoRowIsMappedByStaffNoAndComparedAgainstPaidTimes(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $seeded = $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'staff_no' => '25608',
            'last_name_kana' => 'ベツメイ',
            'first_name_kana' => 'タロウ',
            'short_name' => null,
        ]);

        $batch = $this->uploadYamato($admin, [$this->yamatoRow('25608', 'アドカリ スヂップ')]);
        $row = $batch['rows'][0];

        $this->assertSame('yamato_staff_data', $batch['source']);
        $this->assertSame('exact', $row['match_status']);
        $this->assertSame('staff_no', $row['match_method']);
        $this->assertSame('25608', $row['external_staff_no']);
        $this->assertSame($seeded['employee']->id, $row['user']['id']);
        $this->assertSame($seeded['checkIn']->id, $row['check_in_id']);
        $this->assertSame('matched', $row['compare_status']);
        $this->assertSame(['work_minutes'], $row['summary_differs']);
    }

    public function testUnknownStaffNoDoesNotAutoMapOnAnExactNameHit(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $seeded = $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'staff_no' => '11111',
            'last_name_kana' => 'アドカリ',
            'first_name_kana' => 'スヂップ',
            'short_name' => null,
        ]);

        $row = $this->uploadYamato($admin, [$this->yamatoRow('99999', 'アドカリ スヂップ')])['rows'][0];

        $this->assertSame('similar', $row['match_status']);
        $this->assertNull($row['user']);
        $this->assertSame([$seeded['employee']->id], array_column($row['candidates'], 'id'));
    }

    public function testStaffNoWithLeadingZeroOrFullWidthDigitsStillMatches(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $first = $this->makeEmployeeWithCheckIn($this->makeWorkplace('A'), ['staff_no' => '25608']);
        $second = $this->makeEmployeeWithCheckIn($this->makeWorkplace('B'), ['staff_no' => '39022']);

        $rows = $this->uploadYamato($admin, [
            $this->yamatoRow('025608', 'アドカリ スヂップ'),
            $this->yamatoRow('３９０２２', 'アフマド シェラズ'),
        ])['rows'];

        $this->assertSame(
            [$first['employee']->id, $second['employee']->id],
            array_column(array_column($rows, 'user'), 'id')
        );
        $this->assertSame(['staff_no', 'staff_no'], array_column($rows, 'match_method'));
    }

    public function testStaffNosCollidingAfterNormalizationRequireManualPick(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $short = $this->makeEmployeeWithCheckIn($this->makeWorkplace('A'), ['staff_no' => '123']);
        $padded = $this->makeEmployeeWithCheckIn($this->makeWorkplace('B'), ['staff_no' => '00123']);

        $row = $this->uploadYamato($admin, [$this->yamatoRow('00123', 'アドカリ スヂップ')])['rows'][0];

        $this->assertSame('ambiguous', $row['match_status']);
        $this->assertNull($row['match_method']);
        $this->assertNull($row['user']);
        $this->assertNull($row['check_in_id']);
        $this->assertEqualsCanonicalizing(
            [$short['employee']->id, $padded['employee']->id],
            array_column($row['candidates'], 'id')
        );
    }

    public function testRowWithoutStaffNoFallsBackToNameMatching(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $seeded = $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'staff_no' => '25608',
            'last_name_kana' => 'アドカリ',
            'first_name_kana' => 'スヂップ',
            'short_name' => null,
        ]);

        $row = $this->uploadYamato($admin, [
            array_merge($this->yamatoRow('25608', 'アドカリ スヂップ'), ['staff_no' => null]),
        ])['rows'][0];

        $this->assertNull($row['external_staff_no']);
        $this->assertSame('exact', $row['match_status']);
        $this->assertSame('exact_kana', $row['match_method']);
        $this->assertSame($seeded['employee']->id, $row['user']['id']);
    }

    public function testSameStaffNoTwiceOnOneDateIsFlaggedAsDuplicateAndStaysFlaggedAfterUnmap(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $seeded = $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'staff_no' => '25608',
            'short_name' => null,
        ]);

        $batch = $this->uploadYamato($admin, [
            $this->yamatoRow('25608', 'アドカリ スヂップ'),
            $this->yamatoRow('25608', 'ベツ ナマエ'),
        ]);
        $this->assertSame(['duplicate_external', 'duplicate_external'], array_column($batch['rows'], 'match_status'));

        $rowId = $batch['rows'][0]['id'];
        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'user_id' => $seeded['employee']->id,
            ])
            ->assertOk();
        $unmapped = $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", ['user_id' => null])
            ->assertOk();

        $this->assertSame(
            'duplicate_external',
            collect($unmapped->json('data.rows'))->firstWhere('id', $rowId)['match_status']
        );
    }

    public function testFactoryLifecycleFromUploadToExport(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $seeded = $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'staff_no' => '39008',
            'short_name' => null,
        ]);
        $path = sys_get_temp_dir() . '/factory-' . uniqid() . '.xlsx';
        $this->writeFactoryFile($path, [
            [
                'staff_no' => 39008,
                'name' => 'BIYAGAMA LAKRUWAN',
                'date' => '2026-08-25',
                'in' => '21:30',
                'out' => '06:00',
                'break' => 60,
                'summary' => [
                    'work_minutes_normal' => 90,
                    'work_minutes_overtime' => 0,
                    'work_minutes_night' => 360,
                    'work_minutes_night_overtime' => 0,
                ],
            ],
        ]);

        $batch = $this->actingAs($admin, 'api')
            ->post('/manage/work-history-reconciliations', [
                'source' => 'factory_timesheet',
                'file' => new UploadedFile($path, 'factory.xlsx', null, null, true),
            ])
            ->assertCreated()
            ->json('data');
        @unlink($path);
        $row = $batch['rows'][0];

        $this->assertSame('支払い8月', $batch['company_label']);
        $this->assertSame('staff_no', $row['match_method']);
        $this->assertSame($seeded['checkIn']->id, $row['check_in_id']);
        $this->assertSame('differs', $row['compare_status']);
        $this->assertNull($row['decisions']['check_in_time']);
        $this->assertSame(['work_minutes_normal'], $row['summary_differs']);

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$row['id']}", [
                'decisions' => ['check_in_time' => 'amazon'],
            ])
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();

        $this->assertNotNull(ReconciliationBatch::query()->findOrFail($batch['id'])->export_file_path);
    }

    public function testBatchListCanBeFilteredByNewSource(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $this->makeEmployeeWithCheckIn($this->makeWorkplace(), ['staff_no' => '25608']);
        $this->uploadYamato($admin, [$this->yamatoRow('25608', 'アドカリ スヂップ')]);

        $this->actingAs($admin, 'api')
            ->getJson('/manage/work-history-reconciliations?source=yamato_staff_data')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->actingAs($admin, 'api')
            ->getJson('/manage/work-history-reconciliations?source=factory_timesheet')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }

    public function testWrongSourceNamesTheSourceTheFileActuallyMatches(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $yamato = sys_get_temp_dir() . '/yamato-' . uniqid() . '.xlsx';
        $factory = sys_get_temp_dir() . '/factory-' . uniqid() . '.xlsx';
        $amazon = sys_get_temp_dir() . '/amazon-' . uniqid() . '.xlsx';
        $this->writeYamatoFile($yamato, [$this->yamatoRow('25608', 'アドカリ スヂップ')]);
        $this->writeFactoryFile($factory, []);
        $this->writeAmazonKadouFile($amazon, []);

        $cases = [
            ['amazon_kadou', $yamato, 'yamato_staff_data'],
            ['amazon_kadou', $factory, 'factory_timesheet'],
            ['yamato_staff_data', $amazon, 'amazon_kadou'],
            ['yamato_staff_data', $factory, 'factory_timesheet'],
            ['factory_timesheet', $amazon, 'amazon_kadou'],
            ['factory_timesheet', $yamato, 'yamato_staff_data'],
        ];
        foreach ($cases as [$selected, $path, $detected]) {
            $this->actingAs($admin, 'api')
                ->post('/manage/work-history-reconciliations', [
                    'source' => $selected,
                    'file' => new UploadedFile($path, 'wrong.xlsx', null, null, true),
                ])
                ->assertStatus(422)
                ->assertJsonPath('data.reason', 'header_mismatch')
                ->assertJsonPath('data.detected_source', $detected);
        }

        $this->assertSame(0, ReconciliationBatch::query()->count());
        array_map('unlink', [$yamato, $factory, $amazon]);
    }

    public function testUnrecognisedFileReportsNoDetectedSource(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $path = sys_get_temp_dir() . '/unknown-' . uniqid() . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'not a timesheet');
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($admin, 'api')
            ->post('/manage/work-history-reconciliations', [
                'source' => 'yamato_staff_data',
                'file' => new UploadedFile($path, 'unknown.xlsx', null, null, true),
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'header_mismatch')
            ->assertJsonPath('data.detected_source', null);
        @unlink($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function yamatoRow(string $staffNo, string $nameKana): array
    {
        return [
            'staff_no' => $staffNo,
            'name_kana' => $nameKana,
            'date' => '2026-08-25',
            'in' => '21:00',
            'out' => '30:00',
            'breaks' => [['25:00', '26:00']],
            'summary' => [
                'work_minutes' => 420,
                'work_minutes_normal' => 120,
                'work_minutes_overtime' => 0,
                'work_minutes_night' => 360,
                'work_minutes_night_overtime' => 0,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function uploadYamato(User $admin, array $rows): array
    {
        Storage::fake();
        $path = sys_get_temp_dir() . '/yamato-' . uniqid() . '.xlsx';
        $this->writeYamatoFile($path, $rows);

        $data = $this->actingAs($admin, 'api')
            ->post('/manage/work-history-reconciliations', [
                'source' => 'yamato_staff_data',
                'file' => new UploadedFile($path, 'yamato.xlsx', null, null, true),
            ])
            ->assertCreated()
            ->json('data');
        @unlink($path);

        return $data;
    }
}
