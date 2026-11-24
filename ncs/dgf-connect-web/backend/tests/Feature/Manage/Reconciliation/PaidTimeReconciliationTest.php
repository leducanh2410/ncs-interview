<?php

namespace Tests\Feature\Manage\Reconciliation;

use App\Models\ReconciliationBatch;
use App\Models\ReconciliationRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsAmazonKadouWorkbook;
use Tests\Concerns\SeedsReconciliationFixtures;
use Tests\TestCase;

class PaidTimeReconciliationTest extends TestCase
{
    use BuildsAmazonKadouWorkbook;
    use RefreshDatabase;
    use SeedsReconciliationFixtures;

    public function testSpacedAmazonNameIsMappedAndComparedAgainstPaidTimes(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $row = $this->upload($admin, [$this->amazonRow('パテル バルジュ　クマル')])['rows'][0];

        $this->assertSame('exact', $row['match_status']);
        $this->assertSame($seeded['employee']->id, $row['user']['id']);
        $this->assertSame('matched', $row['compare_status']);
        $this->assertSame('パテル バルジュ クマル', $row['external_name']);
        $this->assertSame('2026-08-25 21:00:00', $row['external_check_in']);
        $this->assertSame('2026-08-26 06:00:00', $row['external_check_out']);
        $this->assertSame('2026-08-25 21:00:00', $row['dgf_check_in']);
        $this->assertSame('2026-08-26 06:00:00', $row['dgf_check_out']);
        $this->assertArrayNotHasKey('punch_range', $row);
        $this->assertArrayNotHasKey('scheduled_range', $row);
        $this->assertSame(
            [
                'work_minutes' => 480,
                'work_minutes_normal' => 60,
                'work_minutes_overtime' => 0,
                'work_minutes_night' => 420,
                'work_minutes_night_overtime' => 0,
            ],
            $row['external_summary']
        );
        $this->assertSame(
            [
                'work_minutes' => 480,
                'work_minutes_normal' => 120,
                'work_minutes_overtime' => 0,
                'work_minutes_night' => 360,
                'work_minutes_night_overtime' => 0,
            ],
            $row['dgf_summary']
        );
        $this->assertSame(['work_minutes_normal', 'work_minutes_night'], $row['summary_differs']);
    }

    public function testAmazonPaidTimeDecisionIsExportedAsCheckInTime(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $batch = $this->upload($admin, [
            $this->amazonRow('パテル バルジュクマル', ['work_in' => '2026-08-25 21:30:00']),
        ]);
        $row = $batch['rows'][0];
        $this->assertSame('differs', $row['compare_status']);
        $this->assertNull($row['decisions']['check_in_time']);
        $this->assertSame('dgf', $row['decisions']['check_out_time']);
        $this->assertSame('dgf', $row['decisions']['break_minutes']);

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

        $relative = ReconciliationBatch::query()->findOrFail($batch['id'])->export_file_path;
        Cell::setValueBinder(new DefaultValueBinder());
        $sheet = IOFactory::createReader('Xlsx')->load(Storage::path($relative))->getSheet(0);
        $this->assertEqualsWithDelta((21 * 60 + 30) / 1440, (float) $sheet->getCell('G3')->getValue(), 0.0001);
        $this->assertEqualsWithDelta((6 * 60) / 1440, (float) $sheet->getCell('H3')->getValue(), 0.0001);
        $this->assertEqualsWithDelta((21 * 60 + 30) / 1440, (float) $sheet->getCell('I3')->getValue(), 0.0001);
        $this->assertSame($seeded['checkIn']->id, (int) $sheet->getCell('Q3')->getValue());
    }

    public function testAmazonDecisionOnFieldMatchingPaidTimeIsNotExported(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $batch = $this->upload($admin, [$this->amazonRow('パテル バルジュクマル')]);
        $row = $batch['rows'][0];
        $this->assertSame('matched', $row['compare_status']);

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
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_NOTHING_TO_EXPORT');
    }

    public function testConfirmedBatchShowsPaidSnapshotAndBreakdown(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $batch = $this->upload($admin, [$this->amazonRow('パテル バルジュクマル')]);
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();

        $seeded['checkIn']->update([
            'check_in_time_rounded' => '2026-08-25 22:00:00',
            'work_minutes' => 420,
            'work_minutes_normal' => 60,
        ]);

        $row = $this->actingAs($admin, 'api')
            ->getJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk()
            ->json('data.rows.0');

        $this->assertSame('2026-08-25 21:00:00', $row['dgf_check_in']);
        $this->assertSame(480, $row['dgf_summary']['work_minutes']);
        $this->assertSame(120, $row['dgf_summary']['work_minutes_normal']);
    }

    public function testNoRoundingWarningWhenAmazonTimeSurvivesDgfRounding(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $row = $this->upload($admin, [$this->amazonRow('パテル バルジュクマル', ['work_in' => '2026-08-25 21:30:00'])])[
            'rows'
        ][0];

        $this->assertSame('differs', $row['compare_status']);
        $this->assertSame([], $row['rounding_warnings']);
    }

    public function testWarnsWhenDgfWouldRoundAmazonCheckInDifferently(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $row = $this->upload($admin, [$this->amazonRow('パテル バルジュクマル', ['work_in' => '2026-08-25 21:40:00'])])[
            'rows'
        ][0];

        $this->assertSame(['check_in_time' => '2026-08-25 21:45:00'], $row['rounding_warnings']);
    }

    public function testWarnsWhenImportCannotRemoveSecondsThatPushedDgfRoundingUp(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn(
            $workplace,
            [
                'last_name_kana' => 'パテル',
                'first_name_kana' => 'バルジュクマル',
                'short_name' => null,
            ],
            checkInAttrs: [
                'check_in_time' => '2026-08-25 21:00:40',
                'check_in_time_rounded' => '2026-08-25 21:15:00',
            ]
        );

        $row = $this->upload($admin, [$this->amazonRow('パテル バルジュクマル')])['rows'][0];

        $this->assertSame('differs', $row['compare_status']);
        $this->assertSame(['check_in_time' => '2026-08-25 21:15:00'], $row['rounding_warnings']);
    }

    public function testWarnsWhenAmazonCheckOutFallsOutsideShiftWithoutOvertime(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $row = $this->upload($admin, [
            $this->amazonRow('パテル バルジュクマル', ['work_out' => '2026-08-26 06:30:00']),
        ])['rows'][0];

        $this->assertSame(['check_out_time' => '2026-08-26 06:00:00'], $row['rounding_warnings']);
    }

    public function testNoCheckOutWarningWhenOvertimeIsAllowed(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);
        $seeded['shiftUser']->update(['is_overtime_allowed' => true]);

        $row = $this->upload($admin, [
            $this->amazonRow('パテル バルジュクマル', ['work_out' => '2026-08-26 06:30:00']),
        ])['rows'][0];

        $this->assertSame('differs', $row['compare_status']);
        $this->assertSame([], $row['rounding_warnings']);
    }

    public function testConfirmedBatchCarriesNoRoundingWarning(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $batch = $this->upload($admin, [
            $this->amazonRow('パテル バルジュクマル', ['work_in' => '2026-08-25 21:40:00']),
        ]);
        $row = $batch['rows'][0];
        $this->assertNotSame([], $row['rounding_warnings']);

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$row['id']}", [
                'decisions' => ['check_in_time' => 'amazon'],
            ])
            ->assertOk();
        $confirmed = $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk()
            ->json('data.rows.0');

        $this->assertSame([], $confirmed['rounding_warnings']);
    }

    public function testConfirmReopensFieldWhenOnlyDgfPaidTimeChanged(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $batch = $this->upload($admin, [$this->amazonRow('パテル バルジュクマル')]);
        $row = $batch['rows'][0];
        $this->assertSame('matched', $row['compare_status']);
        $this->assertSame('dgf', $row['decisions']['check_in_time']);

        $seeded['checkIn']->update(['check_in_time_rounded' => '2026-08-25 21:15:00']);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_DECISIONS_INCOMPLETE')
            ->assertJsonPath('data.unfinished_row_ids', [$row['id']]);

        $stored = ReconciliationRow::query()->findOrFail($row['id']);
        $this->assertSame('differs', $stored->compare_status);
        $this->assertNull($stored->decision_check_in);
        $this->assertSame('dgf', $stored->decision_check_out);
        $this->assertSame('2026-08-25 21:15:00', $stored->snapshot_dgf_paid['check_in']);
    }

    public function testExportWritesPaidColumnsAsDgfWillRoundThem(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $batch = $this->upload($admin, [
            $this->amazonRow('パテル バルジュクマル', ['work_in' => '2026-08-25 21:40:00']),
        ]);
        $row = $batch['rows'][0];
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

        $relative = ReconciliationBatch::query()->findOrFail($batch['id'])->export_file_path;
        Cell::setValueBinder(new DefaultValueBinder());
        $sheet = IOFactory::createReader('Xlsx')->load(Storage::path($relative))->getSheet(0);
        $this->assertEqualsWithDelta((21 * 60 + 40) / 1440, (float) $sheet->getCell('G3')->getValue(), 0.0001);
        $this->assertEqualsWithDelta((21 * 60 + 45) / 1440, (float) $sheet->getCell('I3')->getValue(), 0.0001);
        $this->assertEqualsWithDelta((6 * 60) / 1440, (float) $sheet->getCell('J3')->getValue(), 0.0001);
    }

    public function testExportLeavesPaidColumnsBlankWhenChosenTimesCannotBeRounded(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $batch = $this->upload($admin, [
            $this->amazonRow('パテル バルジュクマル', [
                'work_in' => '2026-08-26 07:00:00',
                'work_out' => '2026-08-26 08:00:00',
            ]),
        ]);
        $row = $batch['rows'][0];
        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$row['id']}", [
                'decisions' => ['check_in_time' => 'amazon', 'check_out_time' => 'dgf'],
            ])
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();

        $relative = ReconciliationBatch::query()->findOrFail($batch['id'])->export_file_path;
        Cell::setValueBinder(new DefaultValueBinder());
        $sheet = IOFactory::createReader('Xlsx')->load(Storage::path($relative))->getSheet(0);
        $this->assertEqualsWithDelta((7 * 60) / 1440, (float) $sheet->getCell('G3')->getValue(), 0.0001);
        $this->assertEqualsWithDelta((6 * 60) / 1440, (float) $sheet->getCell('H3')->getValue(), 0.0001);
        $this->assertNull($sheet->getCell('I3')->getValue());
        $this->assertNull($sheet->getCell('J3')->getValue());
    }

    public function testUploaderCarriesAvatarFields(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $admin->update(['staff_no' => '4242', 'short_name' => null, 'last_name' => 'NGUYEN', 'first_name' => 'AN']);
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $batch = $this->upload($admin, [$this->amazonRow('パテル バルジュクマル')]);

        $this->assertSame($admin->id, $batch['uploaded_by']['id']);
        $this->assertSame('4242', $batch['uploaded_by']['staff_no']);
        $this->assertSame('NGUYEN AN', $batch['uploaded_by']['name']);
        $this->assertArrayHasKey('profile_picture', $batch['uploaded_by']);
    }

    public function testEmployeeWithoutShortNameIsShownWithFullName(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name' => 'PATEL',
            'first_name' => 'BALJU',
            'last_name_kana' => 'パテル',
            'first_name_kana' => 'バルジュクマル',
            'short_name' => null,
        ]);

        $row = $this->upload($admin, [$this->amazonRow('パテル バルジュクマル')])['rows'][0];
        $this->assertSame('PATEL BALJU', $row['user']['name']);

        $found = $this->actingAs($admin, 'api')
            ->getJson('/manage/work-history-reconciliations/users?q=パテル&work_date=2026-08-25')
            ->assertOk()
            ->json('data');
        $this->assertSame('PATEL BALJU', $found[0]['name']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function amazonRow(string $name, array $overrides = []): array
    {
        return array_merge(
            [
                'name' => $name,
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:10:00',
                'punch_out' => '2026-08-26 06:05:00',
                'work_in' => '2026-08-25 21:00:00',
                'work_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
                'summary' => [
                    'work_minutes' => 480 / 1440,
                    'work_minutes_normal' => 60 / 1440,
                    'work_minutes_overtime' => 0,
                    'work_minutes_night' => 420 / 1440,
                    'work_minutes_night_overtime' => 0,
                ],
            ],
            $overrides
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function upload(User $admin, array $rows): array
    {
        $path = sys_get_temp_dir() . '/recon-' . uniqid() . '.xlsx';
        $this->writeAmazonKadouFile($path, $rows);
        $created = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, 'amazon.xlsx', null, null, true),
        ]);
        $created->assertCreated();
        @unlink($path);

        return $created->json('data');
    }
}
