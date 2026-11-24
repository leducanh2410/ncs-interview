<?php

namespace Tests\Unit\Jobs;

use App\Enums\ExportBatchStatus;
use App\Enums\ExportBatchType;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Jobs\WorkHistoryExportJob;
use App\Models\CheckIn;
use App\Models\ExportBatch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Services\Manage\WorkHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;
use ZipArchive;

class WorkHistoryExportJobTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-20 10:00:00');
        Storage::fake('local');
        Log::spy();
        Queue::fake();

        $this->manager = User::factory()->create();

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        foreach ([PermissionName::WorkHistoryList, PermissionName::WorkHistoryExport] as $permName) {
            $permission = Permission::firstOrCreate(['name' => $permName->value], ['display_name' => $permName->value]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->manager->addRole($role);
    }

    public function testGeneratesZipWithOneFilePerWorkplace(): void
    {
        $this->seedCheckIn('Workplace A', '2026-05-10');
        $this->seedCheckIn('Workplace B', '2026-05-11');

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
        $this->assertEquals(2, $batch->csv_total_count);
        $this->assertEquals(2, $batch->csv_completed_count);
        $this->assertNotNull($batch->file_path);
        Storage::assertExists($batch->file_path);

        $this->assertEquals(
            ['Workplace A.xlsx', 'Workplace B.xlsx'],
            $this->readZipEntries(Storage::path($batch->file_path))
        );
    }

    public function testUsesExportPermissionScopeInsteadOfListPermissionScope(): void
    {
        $this->seedCheckIn('Workplace A', '2026-05-10');
        $this->seedCheckIn('Workplace B', '2026-05-11');

        // Manager restricted to a single workplace instead of a global role.
        $restrictedManager = User::factory()->create();
        $workplaceA = Workplace::query()->where('name', 'Workplace A')->firstOrFail();
        $workplaceB = Workplace::query()->where('name', 'Workplace B')->firstOrFail();
        $this->giveWorkplaceScopedPermission($restrictedManager, PermissionName::WorkHistoryList, $workplaceB->id);
        $this->giveWorkplaceScopedPermission($restrictedManager, PermissionName::WorkHistoryExport, $workplaceA->id);

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']], $restrictedManager);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
        $this->assertEquals(['Workplace A.xlsx'], $this->readZipEntries(Storage::path($batch->file_path)));
    }

    public function testExcludesCheckInsWithoutCheckOut(): void
    {
        $this->seedCheckIn('Workplace A', '2026-05-10');
        $this->seedCheckIn('Workplace B', '2026-05-11', withCheckOut: false);

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
        $this->assertEquals(['Workplace A.xlsx'], $this->readZipEntries(Storage::path($batch->file_path)));
    }

    public function testMarksBatchFailedWhenNoDataMatches(): void
    {
        $batch = $this->createBatch(['dates' => ['2020-01-01', '2020-01-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Failed, $batch->status);
        $this->assertNull($batch->file_path);
        $this->assertEquals('No work history data to export.', $batch->error_message);
    }

    public function testFormatsTimeColumnsAndRowStylesInGeneratedExcel(): void
    {
        $this->seedCheckIn(
            'Workplace A',
            '2026-05-10',
            checkInTime: '09:07:00',
            checkOutTime: '18:05:00',
            checkInTimeRounded: '09:15:00',
            checkOutTimeRounded: '18:15:00'
        );

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);

        $sheet = $this->loadZipEntrySheet(Storage::path($batch->file_path), 'Workplace A.xlsx');
        // Column A header falls back to the plain workplace name when no hourly wage is set.
        $this->assertSame('Workplace A', $sheet->getCell('A2')->getValue());
        $this->assertSame('Workplace A', $sheet->getCell('A3')->getValue());

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G3')->getDataType());
        $this->assertEqualsWithDelta((9 * 60 + 7) / 1440, $sheet->getCell('G3')->getValue(), 0.000001);
        $this->assertEqualsWithDelta((18 * 60 + 5) / 1440, $sheet->getCell('H3')->getValue(), 0.000001);
        $this->assertEqualsWithDelta((9 * 60 + 15) / 1440, $sheet->getCell('I3')->getValue(), 0.000001);
        $this->assertEqualsWithDelta((18 * 60 + 15) / 1440, $sheet->getCell('J3')->getValue(), 0.000001);
        foreach (['G3', 'H3', 'I3', 'J3'] as $cell) {
            $this->assertSame('[H]:mm', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode());
        }

        $this->assertFalse($sheet->getStyle('G3')->getAlignment()->getWrapText());
        $this->assertSame(20, (int) $sheet->getDefaultRowDimension()->getRowHeight());
    }

    public function testAppendsHourlyWageToWorkplaceHeaderLabelWhenSet(): void
    {
        $this->seedCheckIn('Workplace A', '2026-05-10', salaryPerHour: 1500);

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);

        $sheet = $this->loadZipEntrySheet(Storage::path($batch->file_path), 'Workplace A.xlsx');
        $this->assertSame('Workplace A - 1500', $sheet->getCell('A2')->getValue());
        // Data rows keep the plain workplace name; only the group header carries the wage.
        $this->assertSame('Workplace A', $sheet->getCell('A3')->getValue());
    }

    public function testOmitsWageSuffixFromWorkplaceHeaderLabelWhenSalaryPerHourIsZero(): void
    {
        // 0 means "not set" system-wide (see ShiftService/SalaryService), so it must not render as "- 0".
        $this->seedCheckIn('Workplace A', '2026-05-10', salaryPerHour: 0);

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);

        $sheet = $this->loadZipEntrySheet(Storage::path($batch->file_path), 'Workplace A.xlsx');
        $this->assertSame('Workplace A', $sheet->getCell('A2')->getValue());
    }

    public function testOvernightCheckOutColumnsShowClockHourNotElapsedFromShiftDate(): void
    {
        $this->seedCheckIn(
            'Workplace A',
            '2026-05-10',
            checkInTime: '21:52:00',
            checkOutTime: '00:38:00',
            checkOutDate: '2026-05-11',
            checkInTimeRounded: '22:00:00',
            checkOutTimeRounded: '00:45:00',
            shiftStartTime: '22:00:00',
            shiftEndTime: '01:00:00'
        );

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);

        $sheet = $this->loadZipEntrySheet(Storage::path($batch->file_path), 'Workplace A.xlsx');
        $this->assertSame('22:00-25:00', $sheet->getCell('C3')->getValue());

        $this->assertEqualsWithDelta((21 * 60 + 52) / 1440, $sheet->getCell('G3')->getValue(), 0.000001);
        $this->assertEqualsWithDelta((0 * 60 + 38) / 1440, $sheet->getCell('H3')->getValue(), 0.000001);
        $this->assertEqualsWithDelta((22 * 60 + 0) / 1440, $sheet->getCell('I3')->getValue(), 0.000001);
        $this->assertEqualsWithDelta((0 * 60 + 45) / 1440, $sheet->getCell('J3')->getValue(), 0.000001);
    }

    public function testShowsRealClockTimeWhenCheckInPrecedesShiftDate(): void
    {
        $this->seedCheckIn(
            'Workplace A',
            '2026-05-10',
            checkInTime: '23:30:00',
            checkInDate: '2026-05-09',
            checkOutTime: '08:30:00',
            shiftStartTime: '00:30:00',
            shiftEndTime: '08:30:00'
        );

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);

        $sheet = $this->loadZipEntrySheet(Storage::path($batch->file_path), 'Workplace A.xlsx');
        $this->assertEqualsWithDelta((23 * 60 + 30) / 1440, $sheet->getCell('G3')->getValue(), 0.000001);
        $this->assertEqualsWithDelta((8 * 60 + 30) / 1440, $sheet->getCell('H3')->getValue(), 0.000001);
    }

    public function testLeavesRoundedTimeColumnsBlankWhenRoundedValueIsMissing(): void
    {
        $checkIn = $this->seedCheckIn('Workplace A', '2026-05-10');
        $checkIn->update(['check_in_time_rounded' => null, 'check_out_time_rounded' => null]);

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);

        $sheet = $this->loadZipEntrySheet(Storage::path($batch->file_path), 'Workplace A.xlsx');
        $this->assertNull($sheet->getCell('I3')->getValue());
        $this->assertNull($sheet->getCell('J3')->getValue());
    }

    public function testIncludesHiddenCheckInIdColumn(): void
    {
        $checkInA = $this->seedCheckIn('Workplace A', '2026-05-10');
        $checkInB = $this->seedCheckIn('Workplace A', '2026-05-11');

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);

        $sheet = $this->loadZipEntrySheet(Storage::path($batch->file_path), 'Workplace A.xlsx');

        $this->assertSame('Check_In_ID', $sheet->getCell('Q1')->getValue());
        $this->assertFalse($sheet->getColumnDimension('Q')->getVisible());

        // Data rows carry their own check_in id, and it uniquely identifies each row.
        $this->assertSame($checkInA->id, $sheet->getCell('Q3')->getValue());
        $this->assertSame($checkInB->id, $sheet->getCell('Q4')->getValue());
        $this->assertNotSame($sheet->getCell('Q3')->getValue(), $sheet->getCell('Q4')->getValue());

        // The workplace-header row (row 2) and the blank separator rows after the data are not
        // data rows, so column Q stays empty on them.
        $this->assertNull($sheet->getCell('Q2')->getValue());
        $this->assertNull($sheet->getCell('Q5')->getValue());
        $this->assertNull($sheet->getCell('Q6')->getValue());
    }

    public function testBreakColumnPrefersCheckInLevelOverrideOverShiftDefault(): void
    {
        // The workplace shift's break_duration is 60 (seedCheckIn default); a per-check-in
        // override (as set by an approved work-history edit request) must win over it so the
        // exported "Giờ nghỉ" column reflects the actual value used for payroll, not a stale
        // shift-level default.
        $this->seedCheckIn('Workplace A', '2026-05-10', breakMinutes: 45);

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);

        $sheet = $this->loadZipEntrySheet(Storage::path($batch->file_path), 'Workplace A.xlsx');
        $this->assertEqualsWithDelta(45 / 1440, $sheet->getCell('K3')->getValue(), 0.000001);
    }

    public function testBreakColumnUsesAnExplicitShiftLevelZeroInsteadOfTheWorkplaceDefault(): void
    {
        // Regression: `if ($shift->break_minutes)` treated a shift-level override of 0 ("no
        // break for this shift") as if it were unset and fell back to the workplace shift's
        // break_duration (60) - the export must honor an explicit 0 the same way
        // PreviewBulkImportWorkHistoryEditRequestsAction::reconcileRow already does with `??`.
        $this->seedCheckIn('Workplace A', '2026-05-10', shiftBreakMinutes: 0);

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);

        $sheet = $this->loadZipEntrySheet(Storage::path($batch->file_path), 'Workplace A.xlsx');
        $this->assertEqualsWithDelta(0.0, $sheet->getCell('K3')->getValue(), 0.000001);
    }

    public function testBreakColumnDefaultsToZeroWhenNoBreakSourceIsSet(): void
    {
        // Regression: when none of Check_In.break_minutes, Shift.break_minutes, or
        // WorkplaceShift.break_duration has a value, the column must not stay an empty string -
        // `'' / 1440` throws a TypeError when the export writes the cell.
        $this->seedCheckIn('Workplace A', '2026-05-10', workplaceShiftBreakDuration: null);

        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);

        (new WorkHistoryExportJob($batch->id))->handle(app(WorkHistoryService::class));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);

        $sheet = $this->loadZipEntrySheet(Storage::path($batch->file_path), 'Workplace A.xlsx');
        $this->assertEqualsWithDelta(0.0, $sheet->getCell('K3')->getValue(), 0.000001);
    }

    public function testFailedCallbackMarksBatchFailedAndCleansUpFile(): void
    {
        $this->seedCheckIn('Workplace A', '2026-05-10');
        $batch = $this->createBatch(['dates' => ['2026-05-01', '2026-05-31']]);
        $batch->update(['file_path' => 'work-history-exports/1/partial.zip']);
        Storage::put('work-history-exports/1/partial.zip', 'partial');

        (new WorkHistoryExportJob($batch->id))->failed(new \RuntimeException('Disk full'));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Failed, $batch->status);
        $this->assertNull($batch->file_path);
        $this->assertEquals('Disk full', $batch->error_message);
        Storage::assertMissing('work-history-exports/1/partial.zip');
    }

    private function createBatch(array $conditions, ?User $exporter = null): ExportBatch
    {
        return ExportBatch::query()->create([
            'type' => ExportBatchType::WorkHistory,
            'exported_by' => ($exporter ?? $this->manager)->id,
            'status' => ExportBatchStatus::Pending,
            'csv_total_count' => 0,
            'csv_completed_count' => 0,
            'csv_failed_count' => 0,
            'meta' => ['conditions' => $conditions],
        ]);
    }

    private function seedCheckIn(
        string $workplaceName,
        string $date,
        bool $withCheckOut = true,
        string $checkInTime = '09:00:00',
        string $checkOutTime = '18:00:00',
        ?string $checkInDate = null,
        ?string $checkOutDate = null,
        ?string $checkInTimeRounded = null,
        ?string $checkOutTimeRounded = null,
        string $shiftStartTime = '09:00:00',
        string $shiftEndTime = '18:00:00',
        ?int $breakMinutes = null,
        ?int $workplaceShiftBreakDuration = 60,
        ?int $salaryPerHour = null,
        ?int $shiftBreakMinutes = null
    ): CheckIn {
        $checkInDate ??= $date;
        $checkOutDate ??= $date;
        $checkInTimeRounded ??= $checkInTime;
        $checkOutTimeRounded ??= $checkOutTime;

        $workplace = Workplace::firstOrCreate(
            ['name' => $workplaceName],
            [
                'address' => 'Test Address',
                'latitude' => 35.6895,
                'longitude' => 139.6917,
                'salary_per_hour' => $salaryPerHour,
            ]
        );

        if ($workplace->salary_per_hour !== $salaryPerHour) {
            $workplace->update(['salary_per_hour' => $salaryPerHour]);
        }

        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => $shiftStartTime,
            'end_time' => $shiftEndTime,
            'break_duration' => $workplaceShiftBreakDuration,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $position = WorkplacePosition::query()->firstOrCreate([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => 1,
            'temp_total' => 1,
            'break_minutes' => $shiftBreakMinutes,
        ]);

        $employee = User::factory()->create();
        WorkplaceUser::create([
            'user_id' => $employee->id,
            'workplace_id' => $workplace->id,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        return CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $employee->id,
            'check_in_time' => "{$checkInDate} {$checkInTime}",
            'check_in_time_rounded' => "{$checkInDate} {$checkInTimeRounded}",
            'check_out_time' => $withCheckOut ? "{$checkOutDate} {$checkOutTime}" : null,
            'check_out_time_rounded' => $withCheckOut ? "{$checkOutDate} {$checkOutTimeRounded}" : null,
            'work_minutes' => $withCheckOut ? 480 : null,
            'work_minutes_normal' => $withCheckOut ? 480 : 0,
            'break_minutes' => $breakMinutes,
        ]);
    }

    /**
     * Extracts one entry from the exported ZIP and loads it as a spreadsheet, so cell
     * values/styles can be asserted against the real file the job produced.
     */
    private function loadZipEntrySheet(string $zipPath, string $entryName): Worksheet
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $contents = $zip->getFromName($entryName);
        $zip->close();
        $this->assertNotFalse($contents, "Entry {$entryName} not found in ZIP.");

        $tmpPath = tempnam(sys_get_temp_dir(), 'work_history_export_');
        file_put_contents($tmpPath, $contents);

        try {
            return IOFactory::createReader('Xlsx')->load($tmpPath)->getSheet(0);
        } finally {
            unlink($tmpPath);
        }
    }

    /**
     * @return array<int, string>
     */
    private function readZipEntries(string $zipPath): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath));

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($entries);

        return $entries;
    }
}
