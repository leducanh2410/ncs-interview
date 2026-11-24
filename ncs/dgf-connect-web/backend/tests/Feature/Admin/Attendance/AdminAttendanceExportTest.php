<?php

namespace Tests\Feature\Admin\Attendance;

use App\Actions\Admin\Attendance\ExportMonthlyAttendanceAction;
use App\Enums\AdminAttendanceEventType;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Exports\AdminAttendanceMonthlyExport;
use App\Models\AdminAttendanceSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class AdminAttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private User $adminA;

    private User $adminB;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    protected function setUp(): void
    {
        parent::setUp();

        config(['work.check_in.max_distance' => 200]);

        // The web attendance history (and its export) is GlobalAdmin-only.
        $role = $this->roleWithPermissions(RoleName::GlobalAdmin->value, 'Global Admin', [
            PermissionName::ManagerAuth->value,
            PermissionName::ManagerAttendanceCheckIn->value,
        ]);

        $this->reviewer = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $this->reviewer->addRole($role);

        // Both permissions, since the export only covers admins who can actually self check-in.
        $this->adminA = User::factory()->create(['first_name' => 'AAA', 'last_name' => 'ZZZ', 'staff_no' => '1001']);
        $this->adminA->addRole($role);
        $this->adminB = User::factory()->create(['first_name' => 'BBB', 'last_name' => 'YYY', 'staff_no' => '1002']);
        $this->adminB->addRole($role);

        $this->workplace = Workplace::create([
            'name' => 'Y001 MUSASHINO',
            'address' => 'Address A',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $this->otherWorkplace = Workplace::create([
            'name' => 'Y002 KITA-TOKYO',
            'address' => 'Address B',
            'latitude' => 34.0,
            'longitude' => 135.0,
        ]);
    }

    public function testExportIsForbiddenForNonGlobalAdmin(): void
    {
        $adminRole = $this->roleWithPermissions(RoleName::Admin->value, 'Admin', [PermissionName::ManagerAuth->value]);
        $plainAdmin = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $plainAdmin->addRole($adminRole);

        $response = $this->actingAs($plainAdmin, 'api')->get('/admin/attendance/export?month=2026-08');

        $response->assertStatus(403);
    }

    public function testExportRequiresAMonth(): void
    {
        $response = $this->actingAs($this->reviewer, 'api')->getJson('/admin/attendance/export');

        $response->assertStatus(422);
    }

    public function testExportRejectsAMalformedMonth(): void
    {
        $response = $this->actingAs($this->reviewer, 'api')->getJson('/admin/attendance/export?month=2026-08-01');

        $response->assertStatus(422);
    }

    public function testExportFailsWhenTheMonthHasNoAttendance(): void
    {
        $this->makeSession($this->adminA, '2026-07-31', 120);
        $this->makeSession($this->adminA, '2026-09-01', 120);

        $response = $this->actingAs($this->reviewer, 'api')->getJson('/admin/attendance/export?month=2026-08');

        $response->assertStatus(400);
    }

    public function testExportDownloadsOneSheetPerAdmin(): void
    {
        Excel::fake();
        $this->makeSession($this->adminA, '2026-08-03', 120);
        $this->makeSession($this->adminB, '2026-08-04', 60);

        $response = $this->actingAs($this->reviewer, 'api')->get('/admin/attendance/export?month=2026-08');

        $response->assertStatus(200);
        Excel::assertDownloaded('admin_attendance_202608.xlsx', function (AdminAttendanceMonthlyExport $export) {
            $sheets = $export->sheets();
            $this->assertCount(2, $sheets);
            $this->assertSame(
                [$this->adminA->short_name, $this->adminB->short_name],
                array_map(fn($sheet) => $sheet->title(), $sheets)
            );

            return true;
        });
    }

    public function testExportRestrictsToTheAdminsGivenInUserIds(): void
    {
        Excel::fake();
        $this->makeSession($this->adminA, '2026-08-03', 120);
        $this->makeSession($this->adminB, '2026-08-04', 60);

        $response = $this->actingAs($this->reviewer, 'api')->get(
            "/admin/attendance/export?month=2026-08&user_ids[]={$this->adminB->id}"
        );

        $response->assertStatus(200);
        Excel::assertDownloaded('admin_attendance_202608.xlsx', function (AdminAttendanceMonthlyExport $export) {
            $sheets = $export->sheets();
            $this->assertCount(1, $sheets);
            $this->assertSame($this->adminB->short_name, $sheets[0]->title());

            return true;
        });
    }

    public function testExportSkipsUsersWithoutTheSelfCheckInPermission(): void
    {
        Excel::fake();
        // A leader can sign into the management site but cannot self check-in, so any attendance
        // left on their account (e.g. from before their role changed) stays out of the report.
        $leaderRole = $this->roleWithPermissions('leader', 'Leader', [PermissionName::ManagerAuth->value]);
        $leader = User::factory()->create(['first_name' => 'LLL', 'last_name' => 'WWW', 'staff_no' => '2001']);
        $leader->addRole($leaderRole);
        $this->makeSession($leader, '2026-08-03', 120);
        $this->makeSession($this->adminA, '2026-08-03', 120);

        $this->actingAs($this->reviewer, 'api')->get('/admin/attendance/export?month=2026-08')->assertStatus(200);

        Excel::assertDownloaded('admin_attendance_202608.xlsx', function (AdminAttendanceMonthlyExport $export) use (
            $leader
        ) {
            $titles = array_map(fn($sheet) => $sheet->title(), $export->sheets());

            $this->assertSame([$this->adminA->short_name], $titles);
            $this->assertNotContains($leader->short_name, $titles);

            return true;
        });
    }

    public function testExportFailsWhenOnlyIneligibleUsersHaveAttendance(): void
    {
        $leaderRole = $this->roleWithPermissions('leader', 'Leader', [PermissionName::ManagerAuth->value]);
        $leader = User::factory()->create(['first_name' => 'LLL', 'last_name' => 'WWW', 'staff_no' => '2001']);
        $leader->addRole($leaderRole);
        $this->makeSession($leader, '2026-08-03', 120);

        $response = $this->actingAs($this->reviewer, 'api')->getJson('/admin/attendance/export?month=2026-08');

        $response->assertStatus(400);
    }

    public function testExportCoversEverySelectedAdminAndOnlyThose(): void
    {
        Excel::fake();
        $adminC = User::factory()->create(['first_name' => 'CCC', 'last_name' => 'XXX', 'staff_no' => '1003']);
        $adminC->addRole(Role::where('name', RoleName::GlobalAdmin->value)->firstOrFail());
        $this->makeSession($this->adminA, '2026-08-03', 120);
        $this->makeSession($this->adminB, '2026-08-04', 60);
        $this->makeSession($adminC, '2026-08-05', 90);

        $response = $this->actingAs($this->reviewer, 'api')->get(
            "/admin/attendance/export?month=2026-08&user_ids[]={$this->adminA->id}&user_ids[]={$adminC->id}"
        );

        $response->assertStatus(200);
        Excel::assertDownloaded('admin_attendance_202608.xlsx', function (AdminAttendanceMonthlyExport $export) use (
            $adminC
        ) {
            $titles = array_map(fn($sheet) => $sheet->title(), $export->sheets());

            $this->assertSame([$this->adminA->short_name, $adminC->short_name], $titles);
            $this->assertNotContains($this->adminB->short_name, $titles);

            return true;
        });
    }

    public function testExportRejectsAnUnknownAdminInUserIds(): void
    {
        $response = $this->actingAs($this->reviewer, 'api')->getJson(
            '/admin/attendance/export?month=2026-08&user_ids[]=999999'
        );

        $response->assertStatus(422);
    }

    public function testExportLaysOutTheTemplateWithPerDayTotalsAndAMonthlyTotal(): void
    {
        Excel::fake();
        // Two workplaces on 2026-08-03 (2h + 3h) and one on 2026-08-04 (1h).
        $this->makeSession($this->adminA, '2026-08-03', 120, $this->workplace);
        $this->makeSession($this->adminA, '2026-08-03', 180, $this->otherWorkplace);
        $this->makeSession($this->adminA, '2026-08-04', 60, $this->workplace);

        $this->actingAs($this->reviewer, 'api')->get('/admin/attendance/export?month=2026-08')->assertStatus(200);

        Excel::assertDownloaded('admin_attendance_202608.xlsx', function (AdminAttendanceMonthlyExport $export) {
            $rows = $export->sheets()[0]->array();

            // Row 1 is a spacer; row 2 is the title block; row 3 the header; rows 4+ the sessions.
            $this->assertSame(array_fill(0, 7, null), $rows[0]);
            $this->assertSame('Dữ liệu chấm công ADMIN', $rows[1][0]);
            // Label + name share one cell so a long name can overflow instead of being clipped.
            $this->assertSame('Họ tên: ' . $this->adminA->short_name, $rows[1][1]);
            $this->assertSame('08/2026', $rows[1][6]);
            $this->assertSame(
                ['Ngày làm', 'Xưởng', 'Giờ vào', 'Giờ ra', 'Giờ có mặt tại xưởng', 'Note', 'Tổng giờ'],
                $rows[2]
            );

            $this->assertSame('03/08/2026', $rows[3][0]);
            $this->assertSame('Y001 MUSASHINO', $rows[3][1]);
            $this->assertSame('09:00', $rows[3][2]);
            $this->assertSame('11:00', $rows[3][3]);
            $this->assertEqualsWithDelta(120 / 1440, $rows[3][4], 0.000001);
            $this->assertSame('', $rows[3][5]);
            // The day's total (2h + 3h) sits on the day's first row, the rest are merged into it.
            $this->assertEqualsWithDelta(300 / 1440, $rows[3][6], 0.000001);
            $this->assertNull($rows[4][6]);
            $this->assertEqualsWithDelta(60 / 1440, $rows[5][6], 0.000001);

            // Blank spacer row, then the month total under the "Tổng giờ" column.
            $this->assertSame(array_fill(0, 7, null), $rows[6]);
            $this->assertSame('Tổng giờ theo tháng', $rows[7][5]);
            $this->assertEqualsWithDelta(360 / 1440, $rows[7][6], 0.000001);

            return true;
        });
    }

    public function testRenderedWorkbookPutsTheTemplateAtTheExpectedCoordinates(): void
    {
        // The rows the sheet declares and the rows the writer emits can drift apart (an all-empty
        // spacer row is dropped on write), which silently moves every merge and style one row off.
        // So assert the *rendered* file, not just the row array.
        Storage::fake('local');
        $this->makeSession($this->adminA, '2026-08-03', 120, $this->workplace);
        $this->makeSession($this->adminA, '2026-08-03', 180, $this->otherWorkplace);
        $this->makeSession($this->adminA, '2026-08-04', 60, $this->workplace);

        $result = app(ExportMonthlyAttendanceAction::class)->execute('2026-08', [$this->adminA->id]);
        Excel::store($result['report'], 'export.xlsx', 'local');

        $sheet = $this->loadExportedSheet();

        $this->assertSame($this->adminA->short_name, $sheet->getTitle());
        $this->assertSame('Dữ liệu chấm công ADMIN', $sheet->getCell('A2')->getValue());
        $this->assertSame('Họ tên: ' . $this->adminA->short_name, $sheet->getCell('B2')->getValue());
        $this->assertSame('08/2026', $sheet->getCell('G2')->getValue());
        // The title block holds no merge, so a long name simply overflows into C/D/E.
        $this->assertNull($sheet->getCell('C2')->getValue());
        $this->assertNotContains('C2:E2', array_keys($sheet->getMergeCells()));
        $this->assertSame('Ngày làm', $sheet->getCell('A3')->getValue());
        $this->assertSame('Giờ ra', $sheet->getCell('D3')->getValue());
        $this->assertSame('Tổng giờ', $sheet->getCell('G3')->getValue());
        $this->assertSame('03/08/2026', $sheet->getCell('A4')->getValue());
        $this->assertEqualsWithDelta(300 / 1440, $sheet->getCell('G4')->getValue(), 0.000001);
        $this->assertSame('Tổng giờ theo tháng', $sheet->getCell('F8')->getValue());
        $this->assertEqualsWithDelta(360 / 1440, $sheet->getCell('G8')->getValue(), 0.000001);

        // The day with two workplaces has its total merged across both of its rows.
        $this->assertContains('G4:G5', array_keys($sheet->getMergeCells()));
        $this->assertSame('[H]:mm', $sheet->getStyle('G4')->getNumberFormat()->getFormatCode());
    }

    public function testRenderedWorkbookKeepsUserSuppliedTextOutOfFormulas(): void
    {
        // Workplace names are free text (only "string, max:255"), and PhpSpreadsheet's default
        // binder turns any string starting with "=" into a formula, so a stored name could run
        // as one the moment a global admin opens the report.
        Storage::fake('local');
        $hostile = Workplace::create([
            'name' => '=HYPERLINK("http://evil.test","click")',
            'address' => 'Address C',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->adminA->update(['first_name' => '=1+1', 'last_name' => '=2+2', 'short_name' => null]);
        $this->makeSession($this->adminA, '2026-08-03', 120, $hostile);

        $result = app(ExportMonthlyAttendanceAction::class)->execute('2026-08', [$this->adminA->id]);
        Excel::store($result['report'], 'export.xlsx', 'local');

        $sheet = $this->loadExportedSheet();

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B4')->getDataType());
        $this->assertSame($hostile->name, $sheet->getCell('B4')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
        // Typing every string as text must not drag the duration cells along with it: they have
        // to stay real numbers or Excel cannot apply the [H]:mm format to them.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('E4')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G4')->getDataType());
    }

    public function testExportFollowsTheActingAdminLocaleForLabelsAndDateFormat(): void
    {
        Excel::fake();
        $this->reviewer->update(['settings' => ['lang' => 'ja']]);
        $this->makeSession($this->adminA, '2026-08-03', 120);

        $this->actingAs($this->reviewer, 'api')->get('/admin/attendance/export?month=2026-08')->assertStatus(200);

        Excel::assertDownloaded('admin_attendance_202608.xlsx', function (AdminAttendanceMonthlyExport $export) {
            $rows = $export->sheets()[0]->array();

            $this->assertSame('管理者勤怠データ', $rows[1][0]);
            $this->assertSame('氏名: ' . $this->adminA->short_name, $rows[1][1]);
            $this->assertSame('2026/08', $rows[1][6]);
            $this->assertSame('勤務日', $rows[2][0]);
            // ja formats dates as Y/m/d, unlike the vi d/m/Y above.
            $this->assertSame('2026/08/03', $rows[3][0]);

            return true;
        });
    }

    public function testExportNotesSessionsThatAreMissingACheckOutOrPastTheDeadline(): void
    {
        // Same day as the export, so the checkout window is still open and the note can only come
        // from the flag `recomputeDay()` stored — the admin moved on to another workplace and left
        // this session dangling. `testExportFlagsASessionLeftOpenOnAPastDay` covers the case where
        // the window has since closed, which reads as "quá hạn" instead.
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00'));
        Excel::fake();
        $open = $this->makeSession($this->adminA, '2026-08-03', null, $this->workplace);
        $invalidated = $this->makeSession($this->adminA, '2026-08-03', null, $this->otherWorkplace);
        $invalidated->update(['invalidated_reason' => 'late_checkout']);
        $this->assertTrue($open->has_missing_checkout);

        $this->actingAs($this->reviewer, 'api')->get('/admin/attendance/export?month=2026-08')->assertStatus(200);

        Excel::assertDownloaded('admin_attendance_202608.xlsx', function (AdminAttendanceMonthlyExport $export) {
            $rows = $export->sheets()[0]->array();

            $this->assertSame('Chưa check-out', $rows[3][5]);
            $this->assertNull($rows[3][3]); // no check-out time
            $this->assertEqualsWithDelta(0.0, $rows[3][4], 0.000001); // and no credited hours
            $this->assertSame('Quá hạn check-out', $rows[4][5]);

            return true;
        });

        Carbon::setTestNow();
    }

    public function testExportLeavesAStillRunningSessionUnflagged(): void
    {
        // Checked in an hour ago and still at the workplace: the checkout window has not closed,
        // and nothing marked the session, so the report must not cry wolf.
        Carbon::setTestNow(Carbon::parse('2026-08-03 10:00:00'));
        $this->actingAs($this->adminA, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        Excel::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-03 11:00:00'));
        $this->actingAs($this->reviewer, 'api')->get('/admin/attendance/export?month=2026-08')->assertStatus(200);

        Excel::assertDownloaded('admin_attendance_202608.xlsx', function (AdminAttendanceMonthlyExport $export) {
            $this->assertSame('', $export->sheets()[0]->array()[3][5]);

            return true;
        });

        Carbon::setTestNow();
    }

    public function testExportFlagsASessionLeftOpenOnAPastDay(): void
    {
        // The realistic forgot-to-check-out flow: one real check-in, then nothing ever again.
        // `has_missing_checkout` is written at check-in time, when the session is still the
        // admin's Current_Focus, so it is stored as false and no later event revisits that day.
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00'));
        $this->actingAs($this->adminA, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);
        $this->assertDatabaseHas('admin_attendance_sessions', [
            'user_id' => $this->adminA->id,
            'work_date' => '2026-08-03',
            'has_missing_checkout' => false,
            'invalidated_reason' => null,
        ]);

        Excel::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
        $this->actingAs($this->reviewer, 'api')->get('/admin/attendance/export?month=2026-08')->assertStatus(200);

        Excel::assertDownloaded('admin_attendance_202608.xlsx', function (AdminAttendanceMonthlyExport $export) {
            $rows = $export->sheets()[0]->array();

            $this->assertNull($rows[3][3]); // never checked out
            $this->assertSame('Quá hạn check-out', $rows[3][5]);

            return true;
        });

        Carbon::setTestNow();
    }

    public function testExportReflectsHoursProducedByRealCheckInAndCheckOutRequests(): void
    {
        // Lifecycle: reach the exported state through the real check-in/out endpoint rather than
        // by seeding sessions, so the export is proven against what the flow actually writes.
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00'));
        $this->actingAs($this->adminA, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-08-03 11:30:00'));
        $this->actingAs($this->adminA, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        Excel::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-05 09:00:00'));
        $this->actingAs($this->reviewer, 'api')->get('/admin/attendance/export?month=2026-08')->assertStatus(200);

        Excel::assertDownloaded('admin_attendance_202608.xlsx', function (AdminAttendanceMonthlyExport $export) {
            $rows = $export->sheets()[0]->array();

            $this->assertSame('03/08/2026', $rows[3][0]);
            $this->assertSame('Y001 MUSASHINO', $rows[3][1]);
            $this->assertSame('09:00', $rows[3][2]);
            $this->assertSame('11:30', $rows[3][3]);
            $this->assertEqualsWithDelta(150 / 1440, $rows[3][4], 0.000001);
            $this->assertSame('', $rows[3][5]);
            $this->assertEqualsWithDelta(150 / 1440, $rows[3][6], 0.000001);

            return true;
        });

        Carbon::setTestNow();
    }

    /**
     * Reads back the workbook written to the fake disk.
     *
     * Resets the value binder first: the export installs its own (text-only) binder and
     * PhpSpreadsheet keeps it on a static, so a raw read in the same process would otherwise
     * re-type every numeric cell as text and report the file as something it is not.
     */
    private function loadExportedSheet(): Worksheet
    {
        Cell::setValueBinder(new DefaultValueBinder());

        return IOFactory::createReader('Xlsx')
            ->load(Storage::disk('local')->path('export.xlsx'))
            ->getSheet(0);
    }

    private function roleWithPermissions(string $name, string $displayName, array $permissions): Role
    {
        $role = Role::firstOrCreate(['name' => $name], ['display_name' => $displayName]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['display_name' => $permission]);
        }
        $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', $permissions)->pluck('id')->all());

        return $role;
    }

    private function makeSession(
        User $user,
        string $date,
        ?int $workMinutes,
        ?Workplace $workplace = null
    ): AdminAttendanceSession {
        $workplace = $workplace ?? $this->workplace;
        $checkInAt = Carbon::parse($date)->setTime(9, 0);

        $session = AdminAttendanceSession::create([
            'user_id' => $user->id,
            'workplace_id' => $workplace->id,
            'work_date' => $date,
            'first_check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
            'last_check_out_at' =>
                $workMinutes !== null ? $checkInAt->copy()->addMinutes($workMinutes)->format('Y-m-d H:i:s') : null,
            'work_minutes' => $workMinutes,
            // Seeded directly (bypassing recomputeDay), so the flag must be set to match:
            // no check-out yet => missing. testExportReflectsHoursProducedByRealCheckInAndCheckOutRequests
            // covers the same shape reached through the real endpoint.
            'has_missing_checkout' => $workMinutes === null,
        ]);

        $session->events()->create([
            'type' => AdminAttendanceEventType::CheckIn,
            'event_time' => $checkInAt->format('Y-m-d H:i:s'),
            'coordinate' => '35.0,139.0',
        ]);

        if ($workMinutes !== null) {
            $session->events()->create([
                'type' => AdminAttendanceEventType::CheckOut,
                'event_time' => $checkInAt->copy()->addMinutes($workMinutes)->format('Y-m-d H:i:s'),
                'coordinate' => '35.0,139.0',
            ]);
        }

        return $session;
    }
}
