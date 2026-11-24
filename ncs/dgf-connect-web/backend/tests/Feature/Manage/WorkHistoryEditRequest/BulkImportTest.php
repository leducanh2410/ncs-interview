<?php

namespace Tests\Feature\Manage\WorkHistoryEditRequest;

use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Exports\WorkHistoryDetailExport;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkHistoryEditRequest;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Services\Manage\WorkHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class BulkImportTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private const COL_CHECK_IN_TIME = 6;

    private Workplace $workplace;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $this->manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
    }

    public function testFullLifecycleExportEditPreviewCommitAndListsInHistory(): void
    {
        $checkIn = $this->seedCheckIn();
        $file = $this->exportedFileWithEditedCheckInTime($checkIn, '08:45:00');

        $previewResponse = $this->actingAs($this->manager, 'api')->postJson(
            '/manage/work-history-edit-requests/import/preview',
            ['file' => $file]
        );

        $previewResponse->assertOk();
        $rows = $previewResponse->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame($checkIn->id, $rows[0]['check_in_id']);

        $commitResponse = $this->actingAs($this->manager, 'api')->postJson(
            '/manage/work-history-edit-requests/import/commit',
            [
                'rows' => [
                    [
                        'check_in_id' => $checkIn->id,
                        'items' => $rows[0]['items'],
                        'decision' => 'apply',
                    ],
                ],
            ]
        );

        $commitResponse->assertOk();
        $commitResponse->assertJsonPath('data.applied_count', 1);
        $this->assertSame('2026-05-10 08:45:00', $checkIn->fresh()->check_in_time);

        // Auditability (Requirement 8): the import-created request appears in the normal list,
        // filterable exactly like a single-record request, with its items visible in detail.
        $listResponse = $this->actingAs($this->manager, 'api')->getJson(
            '/manage/work-history-edit-requests?status=approved'
        );

        $listResponse->assertOk();
        $listResponse->assertJsonPath('data.total', 1);
        $listResponse->assertJsonPath('data.data.0.check_in.id', $checkIn->id);
        $this->assertSame('check_in_time', $listResponse->json('data.data.0.items.0.field'));
    }

    public function testRequiresProcessPermissionForBothEndpoints(): void
    {
        $outsider = User::factory()->create();
        $checkIn = $this->seedCheckIn();
        $file = $this->exportedFileWithEditedCheckInTime($checkIn, '08:45:00');

        $this->actingAs($outsider, 'api')
            ->postJson('/manage/work-history-edit-requests/import/preview', ['file' => $file])
            ->assertForbidden();

        $this->actingAs($outsider, 'api')
            ->postJson('/manage/work-history-edit-requests/import/commit', [
                'rows' => [
                    [
                        'check_in_id' => $checkIn->id,
                        'items' => [['field' => 'check_in_time', 'old_value' => null, 'new_value' => null]],
                        'decision' => 'apply',
                    ],
                ],
            ])
            ->assertForbidden();
    }

    public function testRejectsAFileWithRowsOutsideTheCallersManagedWorkplaces(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $checkInOutsideScope = $this->seedCheckIn($otherWorkplace);
        $file = $this->exportedFileWithEditedCheckInTime($checkInOutsideScope, '08:45:00');

        $response = $this->actingAs($this->manager, 'api')->postJson(
            '/manage/work-history-edit-requests/import/preview',
            ['file' => $file]
        );

        $response->assertStatus(403);
    }

    public function testCommitRejectsAMalformedNewValueWith422InsteadOf500(): void
    {
        $checkIn = $this->seedCheckIn();

        $response = $this->actingAs($this->manager, 'api')->postJson(
            '/manage/work-history-edit-requests/import/commit',
            [
                'rows' => [
                    [
                        'check_in_id' => $checkIn->id,
                        'items' => [
                            ['field' => 'check_in_time', 'old_value' => $checkIn->check_in_time, 'new_value' => 'abc'],
                        ],
                        'decision' => 'apply',
                    ],
                ],
            ]
        );

        $response->assertStatus(422);
        $this->assertSame('2026-05-10 09:00:00', $checkIn->fresh()->check_in_time);
    }

    public function testCommitRejectsANullNewValueForCheckInTimeInsteadOfSilentlyUsingNow(): void
    {
        $checkIn = $this->seedCheckIn();

        $response = $this->actingAs($this->manager, 'api')->postJson(
            '/manage/work-history-edit-requests/import/commit',
            [
                'rows' => [
                    [
                        'check_in_id' => $checkIn->id,
                        'items' => [
                            ['field' => 'check_in_time', 'old_value' => $checkIn->check_in_time, 'new_value' => null],
                        ],
                        'decision' => 'apply',
                    ],
                ],
            ]
        );

        $response->assertStatus(422);
        $this->assertSame('2026-05-10 09:00:00', $checkIn->fresh()->check_in_time);
    }

    public function testCommitConflictIncludesTheAbortingRowsIdentity(): void
    {
        $checkIn = $this->seedCheckIn();
        // Simulate the check-in changing after preview was computed but before commit.
        $checkIn->update(['check_in_time' => '2026-05-10 10:00:00']);

        $response = $this->actingAs($this->manager, 'api')->postJson(
            '/manage/work-history-edit-requests/import/commit',
            [
                'rows' => [
                    [
                        'check_in_id' => $checkIn->id,
                        'items' => [
                            [
                                'field' => 'check_in_time',
                                // Stale old_value from the preview, no longer matching live data.
                                'old_value' => '2026-05-10 09:00:00',
                                'new_value' => '2026-05-10 08:45:00',
                            ],
                        ],
                        'decision' => 'apply',
                    ],
                ],
            ]
        );

        $response->assertStatus(409);
        $response->assertJsonPath('data.check_in_id', $checkIn->id);
        $response->assertJsonPath('data.employee_name', $checkIn->user->name);
        $response->assertJsonPath('data.date', '2026/5/10');
    }

    public function testCheckInWithAPriorRejectedRequestCanStillBeImportedAndApplied(): void
    {
        // Accumulated-history: a resolved (rejected) request from a prior lifecycle must not
        // block a later, unrelated bulk import for the same Check_In.
        $checkIn = $this->seedCheckIn();
        WorkHistoryEditRequest::create([
            'check_in_id' => $checkIn->id,
            'workplace_id' => $this->workplace->id,
            'status' => 'rejected',
            'reject_reason' => 'Old, unrelated request',
        ]);

        $file = $this->exportedFileWithEditedCheckInTime($checkIn, '08:45:00');

        $previewResponse = $this->actingAs($this->manager, 'api')->postJson(
            '/manage/work-history-edit-requests/import/preview',
            ['file' => $file]
        );

        $previewResponse->assertOk();
        $this->assertCount(1, $previewResponse->json('data.rows'));
    }

    private function seedCheckIn(?Workplace $workplace = null): CheckIn
    {
        $workplace ??= $this->workplace;

        $employee = User::factory()->create();
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::query()->firstOrCreate(['workplace_id' => $workplace->id, 'name' => 'Staff']);
        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $employee->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
        WorkplaceUser::query()->firstOrCreate(['user_id' => $employee->id, 'workplace_id' => $workplace->id]);
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
            'check_in_time' => '2026-05-10 09:00:00',
            'check_out_time' => '2026-05-10 18:00:00',
            'work_minutes' => 480,
            'work_minutes_normal' => 480,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }

    /**
     * Builds a real exported workbook for the given Check_In (via the real
     * WorkHistoryDetailExport), then overwrites its check-in-time cell to simulate the admin
     * hand-editing the file, and wraps it as an UploadedFile ready to post to the preview
     * endpoint.
     */
    private function exportedFileWithEditedCheckInTime(CheckIn $checkIn, string $newTime): UploadedFile
    {
        $checkIn->load('shift.workplace', 'shift.workplaceShift', 'user');
        $shift = $checkIn->shift;
        $service = app(WorkHistoryService::class);

        $row = [
            'check_in_id' => $checkIn->id,
            'workplace_name' => $shift->workplace->name,
            'date' => Carbon::parse($shift->date)->format('Y/n/j'),
            'shift_time' => "{$shift->workplaceShift->start_time}-{$shift->workplaceShift->end_time}",
            'staff_no' => $checkIn->user->staff_no,
            'name' => $checkIn->user->short_name,
            'name_kana' => $checkIn->user->name_kana,
            'check_in_time' => $service->toDayFraction($checkIn->check_in_time),
            'check_out_time' => $service->toDayFraction($checkIn->check_out_time),
            'check_in_time_rounded' => $service->toDayFraction($checkIn->check_in_time),
            'check_out_time_rounded' => $service->toDayFraction($checkIn->check_out_time),
            'break_time' => (string) ($checkIn->break_minutes ?? ($shift->workplaceShift->break_duration ?? 0)),
            'work_minutes' => $checkIn->work_minutes,
            'work_minutes_normal' => $checkIn->work_minutes_normal,
            'work_minutes_night' => $checkIn->work_minutes_night,
            'work_minutes_overtime' => $checkIn->work_minutes_overtime,
            'work_minutes_night_overtime' => $checkIn->work_minutes_night_overtime,
        ];

        $spreadsheet = new WorkHistoryDetailExport([$shift->workplace_id => [$row]]);
        $content = Excel::raw($spreadsheet, ExcelFormat::XLSX);

        // Round-trip through a real reader/writer so the "hand edit" is applied to an actual
        // cell in a real workbook, not just to the PHP array before export.
        $tmpPath = tempnam(sys_get_temp_dir(), 'bulk_import_feature_test_') . '.xlsx';
        file_put_contents($tmpPath, $content);
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $workbook = $reader->load($tmpPath);
        $sheet = $workbook->getSheet(0);
        $sheet->setCellValue([self::COL_CHECK_IN_TIME + 1, 3], $service->toDayFraction("2026-05-10 {$newTime}"));
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($workbook, 'Xlsx');
        $writer->save($tmpPath);
        $editedContent = file_get_contents($tmpPath);
        unlink($tmpPath);

        return UploadedFile::fake()->createWithContent('work_history.xlsx', $editedContent);
    }
}
