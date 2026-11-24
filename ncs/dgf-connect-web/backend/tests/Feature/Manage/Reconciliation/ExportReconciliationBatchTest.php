<?php

namespace Tests\Feature\Manage\Reconciliation;

use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Tests\Concerns\BuildsAmazonKadouWorkbook;
use Tests\Concerns\SeedsReconciliationFixtures;
use Tests\TestCase;

class ExportReconciliationBatchTest extends TestCase
{
    use BuildsAmazonKadouWorkbook;
    use RefreshDatabase;
    use SeedsReconciliationFixtures;

    public function testExportDraftReturnsNotConfirmed(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $path = sys_get_temp_dir() . '/recon-' . uniqid() . '.xlsx';
        $this->writeAmazonKadouFile($path, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
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
        @unlink($path);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$created->json('data.id')}/export")
            ->assertStatus(409)
            ->assertJsonPath('error', 'RECONCILIATION_BATCH_NOT_CONFIRMED');
    }

    public function testExportIncludesHiddenCheckInIdAndDoesNotWriteAttendance(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();

        $download = $this->actingAs($admin, 'api')->get("/manage/work-history-reconciliations/{$batch['id']}/export");
        $download->assertOk();

        $relative = ReconciliationBatch::query()->findOrFail($batch['id'])->export_file_path;
        $this->assertNotNull($relative);
        Cell::setValueBinder(new DefaultValueBinder());
        $sheet = IOFactory::createReader('Xlsx')->load(Storage::path($relative))->getSheet(0);
        $headings = [];
        for ($col = 1; $col <= 17; $col++) {
            $headings[] = $sheet->getCell([$col, 1])->getValue();
        }
        $this->assertSame('Xưởng', $headings[0]);
        $this->assertSame('Giờ vào làm', $headings[6]);
        $this->assertSame('Giờ tan làm', $headings[7]);
        $this->assertSame('Check_In_ID', $headings[16]);
        $this->assertCount(1, array_filter($headings, fn($heading) => $heading === 'Check_In_ID'));
        $this->assertFalse($sheet->getColumnDimension('Q')->getVisible());

        $dataRow = 3;
        $this->assertEqualsWithDelta(1219 / 1440, (float) $sheet->getCell("G{$dataRow}")->getValue(), 0.0001);
        $this->assertEqualsWithDelta(363 / 1440, (float) $sheet->getCell("H{$dataRow}")->getValue(), 0.0001);
        $this->assertSame($seeded['checkIn']->id, (int) $sheet->getCell("Q{$dataRow}")->getValue());

        $this->assertSame(0, WorkHistoryEditRequest::query()->count());
        $this->assertSame($seeded['checkIn']->check_in_time, $seeded['checkIn']->fresh()->check_in_time);
    }

    public function testNothingToExportWhenAllDgf(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn(
            $workplace,
            [
                'last_name_kana' => 'ウィジェシンヘ',
                'first_name_kana' => 'サミシカ',
                'short_name' => null,
            ],
            checkInAttrs: [
                'check_in_time' => '2026-08-25 20:19:00',
                'check_out_time' => '2026-08-26 06:03:00',
            ]
        );
        $path = sys_get_temp_dir() . '/recon-' . uniqid() . '.xlsx';
        $this->writeAmazonKadouFile($path, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
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
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$id}/confirm")
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$id}/export")
            ->assertStatus(422);
        @unlink($path);
    }

    public function testPendingRowIsOmittedFromExport(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        WorkHistoryEditRequest::create([
            'check_in_id' => $seeded['checkIn']->id,
            'workplace_id' => $workplace->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertStatus(422);
    }

    public function testExportSucceedsWithoutWorkHistoryPermissions(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();
    }

    public function testReExportStaleAfterCheckInChanges(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();

        $seeded['checkIn']->update([
            'check_in_time' => '2026-08-25 19:00:00',
            'check_in_time_rounded' => '2026-08-25 19:00:00',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertStatus(409);
    }

    public function testExportStaleWhenOnlyPaidTimeChangesAfterConfirm(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $seeded = $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $seeded['checkIn']->update(['check_out_time_rounded' => '2026-08-26 05:45:00']);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertStatus(409)
            ->assertJsonPath('data.row_ids', [$batch['rows'][0]['id']]);
    }

    public function testExportStaleWhenOnlyWorkMinutesBreakdownChangesAfterConfirm(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $seeded = $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $seeded['checkIn']->update([
            'work_minutes_normal' => (int) $seeded['checkIn']->work_minutes_normal + 30,
            'work_minutes_night' => (int) $seeded['checkIn']->work_minutes_night - 30,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertStatus(409);
    }

    public function testExportNotStaleWhenRawBreakColumnChangesWithoutMovingEffectiveBreak(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        // break_minutes left null on the check-in, so effectiveBreakMinutes()
        // falls back to the WorkplaceShift default (60, see
        // SeedsReconciliationFixtures::makeEmployeeWithCheckIn) — matching the
        // 60-minute Amazon punch below, so the break agrees while the check-in
        // time genuinely differs.
        $seeded = $this->makeEmployeeWithCheckIn(
            $workplace,
            [
                'last_name_kana' => 'ウィジェシンヘ',
                'first_name_kana' => 'サミシカ',
                'short_name' => null,
            ],
            checkInAttrs: [
                'check_in_time' => '2026-08-25 20:00:00',
                'check_out_time' => '2026-08-26 06:03:00',
                'break_minutes' => null,
            ]
        );
        $path = sys_get_temp_dir() . '/recon-' . uniqid() . '.xlsx';
        $this->writeAmazonKadouFile($path, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
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
        @unlink($path);
        $this->assertSame('differs', $created->json('data.rows.0.compare_status'));
        $batchId = $created->json('data.id');
        $rowId = $created->json('data.rows.0.id');

        // Accepting Amazon's 20:19 over DGF's 20:00 puts the row in the export,
        // so the request actually reaches ExportReconciliationBatchAction's
        // isStale() check below instead of short-circuiting on "nothing to export".
        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batchId}/rows/{$rowId}", [
                'decisions' => ['check_in_time' => 'amazon'],
            ])
            ->assertOk();

        // An approved edit request (or any other write to the check-in) fills
        // in the raw break_minutes column with a value that happens to equal
        // the shift-default fallback the effective break was already using —
        // so effectiveBreakMinutes() is unchanged, but the raw column moved
        // from NULL to 60.
        $seeded['checkIn']->update(['break_minutes' => 60]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batchId}/confirm")
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batchId}/export")
            ->assertOk();
    }

    public function testExportStaleWhenCheckInIsDeletedAfterConfirm(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();

        // The check-in is hard-deleted after Confirm — what AssignShiftUsersAction,
        // SaveShiftPlanDraftAction and RejectUpcomingShiftsAction all do when a
        // user leaves a shift. CheckIn has no SoftDeletes and
        // reconciliation_rows.check_in_id is nullOnDelete, so the confirmed row
        // silently loses its link while keeping the admin's Amazon decisions.
        $seeded['checkIn']->delete();
        $rowId = ReconciliationRow::query()->where('batch_id', $batch['id'])->value('id');
        $this->assertNull(ReconciliationRow::query()->whereKey($rowId)->value('check_in_id'));

        // Re-exporting must report the loss instead of quietly writing a
        // workbook without the row (or, when it was the only row, blaming
        // "nothing to export"), and mark it as one of the rows that can only be
        // skipped — a deleted check-in cannot be recreated with its old id.
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertStatus(409)
            ->assertJsonPath('error', 'RECONCILIATION_STALE')
            ->assertJsonPath('data.row_ids', [$rowId]);
    }

    public function testSkippingTheOrphanedRowUnblocksTheRestOfTheExport(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $lost = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $kept = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'タパ',
            'first_name_kana' => 'ウマ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
            [
                'name' => 'タパ ウマ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $lost['checkIn']->delete();
        $orphanRowId = $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertStatus(409)
            ->json('data.row_ids.0');

        // The screen offers its one write off this flag, so it has to name the
        // same row the export just blamed and the PATCH below will accept.
        $this->assertTrue($this->serializedRow($admin, (int) $batch['id'], (int) $orphanRowId)['can_skip']);
        $keptRowId = ReconciliationRow::query()
            ->where('batch_id', $batch['id'])
            ->where('check_in_id', $kept['checkIn']->id)
            ->value('id');

        // A confirmed batch is otherwise frozen, but without this the whole
        // batch would be stuck forever: the check-in cannot come back with the
        // same id, and one dead row blocks every other row's export.
        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$orphanRowId}", ['skipped' => true])
            ->assertOk();

        // Repeating the skip must stay a no-op rather than 409: the button is
        // still on screen (`can_skip` does not go false once the row is
        // skipped), and a double click or a second admin whose page predates
        // the first skip would otherwise be told the save failed on a row that
        // is already skipped and a batch that is no longer blocked.
        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$orphanRowId}", ['skipped' => true])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();

        // Skipping removes the row from the workbook without rewriting anything
        // that was confirmed: the surviving row keeps its own decisions, and the
        // skipped row keeps the snapshot it was confirmed against.
        $orphan = ReconciliationRow::query()->findOrFail($orphanRowId);
        $this->assertTrue((bool) $orphan->skipped);
        $this->assertNotNull($orphan->snapshot_dgf_check_in);
        $this->assertSame('amazon', $orphan->decision_check_in);
        $this->assertSame('amazon', ReconciliationRow::query()->findOrFail($keptRowId)->decision_check_in);
    }

    /**
     * The skip escape hatch is scoped to rows that actually hold the export up.
     * Export decides "would this row change a DGF value?" before it ever looks
     * at the check-in, so a row whose fields all matched is passed over whether
     * or not its check-in survived — it never reaches the stale check and never
     * appears in `row_ids`. Accepting a write on a frozen batch for such a row
     * would change audit state to unblock something that was never blocked.
     */
    public function testConfirmedBatchRejectsSkippingAnOrphanRowThatDoesNotBlockTheExport(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        // Amazon agrees with DGF to the minute, so this row is `matched` and
        // uploadDecideConfirm() leaves it without a single decision.
        $matched = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'タパ',
            'first_name_kana' => 'ウマ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:15:00',
                'punch_out' => '2026-08-26 06:00:00',
                'work_in' => '2026-08-25 21:00:00',
                'work_out' => '2026-08-26 06:00:00',
                'break' => 60 / 1440,
            ],
            [
                'name' => 'タパ ウマ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $matchedRowId = ReconciliationRow::query()
            ->where('batch_id', $batch['id'])
            ->where('check_in_id', $matched['checkIn']->id)
            ->value('id');

        $matched['checkIn']->delete();

        // The detail screen must not advertise the button either.
        $this->assertFalse($this->serializedRow($admin, (int) $batch['id'], (int) $matchedRowId)['can_skip']);

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$matchedRowId}", [
                'skipped' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'RECONCILIATION_BATCH_NOT_DRAFT');

        $this->assertFalse((bool) ReconciliationRow::query()->findOrFail($matchedRowId)->skipped);

        // And nothing had to be skipped: the orphan was never in the workbook.
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();
    }

    public function testConfirmedBatchStillRejectsEveryOtherRowEdit(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $rowId = ReconciliationRow::query()->where('batch_id', $batch['id'])->value('id');

        // Still linked: nothing may be written at all.
        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", ['skipped' => true])
            ->assertStatus(409)
            ->assertJsonPath('error', 'RECONCILIATION_BATCH_NOT_DRAFT');

        $seeded['checkIn']->delete();

        // Orphaned, but these are not the one allowed write.
        foreach (
            [['skipped' => false], ['decisions' => ['check_in_time' => 'dgf']], ['skipped' => true, 'user_id' => null]]
            as $payload
        ) {
            $this->actingAs($admin, 'api')
                ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", $payload)
                ->assertStatus(409)
                ->assertJsonPath('error', 'RECONCILIATION_BATCH_NOT_DRAFT');
        }

        $this->assertFalse((bool) ReconciliationRow::query()->findOrFail($rowId)->skipped);
    }

    public function testExportSkipsAmazonDecisionOnFieldThatAlreadyMatchesDgf(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn(
            $workplace,
            [
                'last_name_kana' => 'ウィジェシンヘ',
                'first_name_kana' => 'サミシカ',
                'short_name' => null,
            ],
            checkInAttrs: [
                'check_in_time' => '2026-08-25 20:19:00',
                'check_out_time' => '2026-08-26 06:03:00',
            ]
        );
        $path = sys_get_temp_dir() . '/recon-' . uniqid() . '.xlsx';
        $this->writeAmazonKadouFile($path, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
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
        @unlink($path);
        $this->assertSame('matched', $created->json('data.rows.0.compare_status'));
        $batchId = $created->json('data.id');
        $rowId = $created->json('data.rows.0.id');

        // PATCH accepts `amazon` on a field DGF already agrees with. Exporting
        // that row would hand the importer a line with nothing to change
        // (Requirement 10.2/10.5), so it must not reach the workbook.
        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batchId}/rows/{$rowId}", [
                'decisions' => ['check_in_time' => 'amazon'],
            ])
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batchId}/confirm")
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batchId}/export")
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_NOTHING_TO_EXPORT');
    }

    public function testExportDownloadNameIdentifiesTheBatch(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        // Every batch used to download as `export.xlsx`, which makes it easy to
        // import yesterday's batch into Work history edit requests by mistake.
        $filename = $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk()
            ->json('data.filename');
        $this->assertMatchesRegularExpression("/^reconciliation-{$batch['id']}-\d{8}-\d{6}\.xlsx$/", $filename);

        $this->actingAs($admin, 'api')
            ->get("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk()
            ->assertDownload($filename);
    }

    public function testFailedReExportKeepsThePreviousArtifactAndAuditColumns(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();

        $stored = ReconciliationBatch::query()->findOrFail($batch['id']);
        $goodBytes = Storage::get($stored->export_file_path);
        $goodExportedAt = $stored->exported_at;

        // The `local` disk is configured with `throw => false`, so a write that
        // runs out of space comes back as `false`. The batch already points at
        // Monday's artifact under the same path, so a re-export that fails must
        // not leave a truncated file there: the DB rolls back to exactly the
        // export the admin can still download.
        Excel::shouldReceive('store')->once()->andReturnFalse();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertStatus(500);

        $after = ReconciliationBatch::query()->findOrFail($batch['id']);
        $this->assertSame($stored->export_file_path, $after->export_file_path);
        $this->assertEquals($goodExportedAt, $after->exported_at);
        $this->assertSame($goodBytes, Storage::get($after->export_file_path));
        $this->assertEmpty(array_filter(Storage::allFiles(), fn(string $file) => str_ends_with($file, '.writing')));
    }

    public function testZipPublishFailureReportsItsOwnCauseAndNotAZipArchiveError(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $a = $this->makeWorkplace('Alpha');
        $b = $this->makeWorkplace('Beta');
        $this->makeEmployeeWithCheckIn($a, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $this->makeEmployeeWithCheckIn($b, [
            'last_name_kana' => 'タパ',
            'first_name_kana' => 'ウマ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
            [
                'name' => 'タパ ウマ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        // Occupying the destination with a directory makes the rename in
        // promote() fail after the archive has already been closed — the path
        // where a second ZipArchive::close() would raise "Invalid or
        // uninitialized Zip object" and bury the real cause. The equivalent in
        // production is a full disk, which ZIP only reports at close() time.
        Storage::makeDirectory("reconciliation/{$batch['id']}/export.zip");

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin, 'api')->postJson("/manage/work-history-reconciliations/{$batch['id']}/export");
            $this->fail('Expected the export to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Failed to publish the reconciliation export file.', $exception->getMessage());
        }

        $this->assertEmpty(array_filter(Storage::allFiles(), fn(string $file) => str_ends_with($file, '.writing')));
    }

    public function testZipWriterThatThrowsLeavesNoStagingFileBehind(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $a = $this->makeWorkplace('Alpha');
        $b = $this->makeWorkplace('Beta');
        $this->makeEmployeeWithCheckIn($a, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $this->makeEmployeeWithCheckIn($b, [
            'last_name_kana' => 'タパ',
            'first_name_kana' => 'ウマ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
            [
                'name' => 'タパ ウマ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();
        $stored = ReconciliationBatch::query()->findOrFail($batch['id']);
        $goodBytes = Storage::get($stored->export_file_path);

        // Unlike the `false` returns, an exception from Excel::raw() leaves the
        // ZipArchive handle open mid-archive: without the catch/finally the
        // half-written export.zip.writing would stay on disk.
        Excel::shouldReceive('raw')->andThrow(new RuntimeException('forced writer failure'));

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertStatus(500);

        $after = ReconciliationBatch::query()->findOrFail($batch['id']);
        $this->assertSame($stored->export_file_path, $after->export_file_path);
        $this->assertSame($goodBytes, Storage::get($after->export_file_path));
        $this->assertEmpty(array_filter(Storage::allFiles(), fn(string $file) => str_ends_with($file, '.writing')));
    }

    public function testTwoWorkplacesProduceZip(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $a = $this->makeWorkplace('Alpha');
        $b = $this->makeWorkplace('Beta');
        $this->makeEmployeeWithCheckIn($a, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $this->makeEmployeeWithCheckIn($b, [
            'last_name_kana' => 'タパ',
            'first_name_kana' => 'ウマ',
            'short_name' => null,
        ]);
        $batch = $this->uploadDecideConfirm($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
            [
                'name' => 'タパ ウマ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/manage/work-history-reconciliations/{$batch['id']}/export"
        );
        $response->assertOk();
        $this->assertStringEndsWith('.zip', $response->json('data.filename'));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedRow(User $admin, int $batchId, int $rowId): array
    {
        return collect(
            $this->actingAs($admin, 'api')
                ->getJson("/manage/work-history-reconciliations/{$batchId}")
                ->assertOk()
                ->json('data.rows')
        )
            ->keyBy('id')
            ->get($rowId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function uploadDecideConfirm($admin, array $rows): array
    {
        $path = sys_get_temp_dir() . '/recon-' . uniqid() . '.xlsx';
        $this->writeAmazonKadouFile($path, $rows);
        $created = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, 'amazon.xlsx', null, null, true),
        ]);
        $created->assertCreated();
        @unlink($path);
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

        return $data;
    }
}
