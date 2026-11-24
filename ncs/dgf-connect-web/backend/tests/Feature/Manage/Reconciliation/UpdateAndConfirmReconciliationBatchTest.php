<?php

namespace Tests\Feature\Manage\Reconciliation;

use App\Enums\ReconciliationBatchStatus;
use App\Enums\ShiftUserStatus;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\CheckIn;
use App\Models\ReconciliationBatch;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAmazonKadouWorkbook;
use Tests\Concerns\SeedsReconciliationFixtures;
use Tests\TestCase;

class UpdateAndConfirmReconciliationBatchTest extends TestCase
{
    use BuildsAmazonKadouWorkbook;
    use RefreshDatabase;
    use SeedsReconciliationFixtures;

    public function testManualMapCheckInPickAndConfirm(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ロングハウスネーム',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $rowId = $batch['rows'][0]['id'];
        $this->assertNull($batch['rows'][0]['user']);

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'user_id' => $seeded['employee']->id,
            ])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'decisions' => [
                    'check_in_time' => 'amazon',
                    'check_out_time' => 'dgf',
                    'break_minutes' => 'dgf',
                ],
            ])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', ReconciliationBatchStatus::Confirmed->value);

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'decisions' => ['check_in_time' => 'dgf'],
            ])
            ->assertStatus(409);
    }

    public function testConfirmIncompleteReturnsUnfinishedIds(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $batch = $this->upload($admin, [
            [
                'name' => 'Unknown Person',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_DECISIONS_INCOMPLETE');
    }

    public function testSkipUnmatchedAllowsConfirm(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $batch = $this->upload($admin, [
            [
                'name' => 'Unknown Person',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $rowId = $batch['rows'][0]['id'];

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'skipped' => true,
            ])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();
    }

    public function testConfirmRetryIsIdempotent(): void
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
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();
    }

    public function testConfirmDetectsLiveDriftOnPreviouslyMatchedRow(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn(
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
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->assertSame('matched', $batch['rows'][0]['compare_status']);
        $rowId = $batch['rows'][0]['id'];

        // Simulate DGF attendance changing after upload (e.g. an approved edit
        // request) but before Confirm: the row was auto-accepted as `matched`
        // and must not be silently carried through with stale decisions.
        $seeded['checkIn']->update([
            'check_in_time' => '2026-08-25 21:00:00',
            'check_in_time_rounded' => '2026-08-25 21:00:00',
        ]);

        $confirmResponse = $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_DECISIONS_INCOMPLETE');
        $this->assertSame([$rowId], $confirmResponse->json('data.unfinished_row_ids'));

        $refreshed = $this->actingAs($admin, 'api')
            ->getJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk()
            ->json('data');
        $this->assertSame('differs', $refreshed['rows'][0]['compare_status']);
        $this->assertNull($refreshed['rows'][0]['decisions']['check_in_time']);
        $this->assertSame('dgf', $refreshed['rows'][0]['decisions']['check_out_time']);
        $this->assertSame('dgf', $refreshed['rows'][0]['decisions']['break_minutes']);

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'decisions' => ['check_in_time' => 'dgf'],
            ])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', ReconciliationBatchStatus::Confirmed->value);
    }

    public function testConfirmDetectsLiveDriftOnAutoFilledFieldOfDiffersRow(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn(
            $workplace,
            [
                'last_name_kana' => 'ウィジェシンヘ',
                'first_name_kana' => 'サミシカ',
                'short_name' => null,
            ],
            checkInAttrs: [
                // check_in differs from the Amazon punch below (20:00 vs 20:19);
                // check_out and break match it, so applyComparison() auto-fills
                // decision_check_out/decision_break as `dgf` even though the row
                // as a whole is `differs`.
                'check_in_time' => '2026-08-25 20:00:00',
                'check_out_time' => '2026-08-26 06:03:00',
                'break_minutes' => 60,
            ]
        );
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->assertSame('differs', $batch['rows'][0]['compare_status']);
        $this->assertNull($batch['rows'][0]['decisions']['check_in_time']);
        $this->assertSame('dgf', $batch['rows'][0]['decisions']['check_out_time']);
        $this->assertSame('dgf', $batch['rows'][0]['decisions']['break_minutes']);
        $rowId = $batch['rows'][0]['id'];

        // Admin resolves the genuinely-differing field.
        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'decisions' => ['check_in_time' => 'amazon'],
            ])
            ->assertOk();

        // DGF check-out changes after the admin decided (e.g. an approved edit
        // request) — a real Amazon/DGF discrepancy on the field that was only
        // ever auto-filled `dgf`, never actually reviewed.
        $seeded['checkIn']->update([
            'check_out_time' => '2026-08-26 07:00:00',
            'check_out_time_rounded' => '2026-08-26 07:00:00',
        ]);

        $confirmResponse = $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_DECISIONS_INCOMPLETE');
        $this->assertSame([$rowId], $confirmResponse->json('data.unfinished_row_ids'));

        $refreshed = $this->actingAs($admin, 'api')
            ->getJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk()
            ->json('data');
        $this->assertSame('differs', $refreshed['rows'][0]['compare_status']);
        // The admin's real decision on check_in must survive the refresh.
        $this->assertSame('amazon', $refreshed['rows'][0]['decisions']['check_in_time']);
        $this->assertNull($refreshed['rows'][0]['decisions']['check_out_time']);
        $this->assertSame('dgf', $refreshed['rows'][0]['decisions']['break_minutes']);

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'decisions' => ['check_out_time' => 'dgf'],
            ])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', ReconciliationBatchStatus::Confirmed->value);
    }

    public function testConfirmAutoAcceptsFieldThatDriftedBackToAmazonValue(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
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
                'break_minutes' => 60,
            ]
        );
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->assertSame('differs', $batch['rows'][0]['compare_status']);
        $this->assertNull($batch['rows'][0]['decisions']['check_in_time']);
        $rowId = $batch['rows'][0]['id'];

        // An edit request is approved bringing DGF check-in back to exactly the
        // Amazon-punched time (09:05-style scenario: DGF corrected *to* Amazon,
        // not away from it) before the admin ever picks a decision on this row.
        $seeded['checkIn']->update([
            'check_in_time' => '2026-08-25 20:19:00',
            'check_in_time_rounded' => '2026-08-25 20:19:00',
        ]);

        // Confirm must not block on a null decision the FE has no control to
        // set (both sides already read "Matched"): it should auto-accept the
        // now-agreeing field and let the row through.
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', ReconciliationBatchStatus::Confirmed->value);

        $refreshedRow = $this->actingAs($admin, 'api')
            ->getJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk()
            ->json('data.rows.0');
        $this->assertSame($rowId, $refreshedRow['id']);
        $this->assertSame('matched', $refreshedRow['compare_status']);
        $this->assertSame('dgf', $refreshedRow['decisions']['check_in_time']);
    }

    public function testConfirmDropsRowToNotComparableWhenCheckInHardDeletedMidDraft(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
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
                'break_minutes' => 60,
            ]
        );
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $rowId = $batch['rows'][0]['id'];

        // Admin resolves the genuine check-in mismatch to Amazon before the
        // check-in is removed from under the batch.
        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'decisions' => ['check_in_time' => 'amazon'],
            ])
            ->assertOk();

        // The shift assignment carrying this check-in is removed while the
        // batch is still Draft (e.g. AssignShiftUsersAction rebuilding the
        // shift's roster) — CheckIn has no SoftDeletes, and
        // reconciliation_rows.check_in_id is nullOnDelete, so the FK sets it
        // to NULL underneath the still-Draft row.
        $seeded['checkIn']->delete();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', ReconciliationBatchStatus::Confirmed->value);

        $refreshedRow = $this->actingAs($admin, 'api')
            ->getJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk()
            ->json('data.rows.0');
        $this->assertSame($rowId, $refreshedRow['id']);
        $this->assertSame('not_comparable', $refreshedRow['compare_status']);
        $this->assertNull($refreshedRow['check_in_id']);
        $this->assertNull($refreshedRow['decisions']['check_in_time']);

        // With the check-in gone, there is nothing left to overlay/export —
        // the stale `amazon` decision must not carry a phantom row into
        // Export.
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_NOTHING_TO_EXPORT');
    }

    public function testConfirmBindsCheckInEnteredAfterUpload(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $employee = User::factory()->create([
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $workplace = $this->makeWorkplace();

        // Uploaded while DGF has no attendance for this employee at all: the
        // row maps to the user but never gets bound to a check-in.
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $rowId = $batch['rows'][0]['id'];
        $this->assertSame($employee->id, $batch['rows'][0]['user']['id']);
        $this->assertSame('none', $batch['rows'][0]['check_in_status']);
        $this->assertNull($batch['rows'][0]['check_in_id']);

        // DGF attendance is entered late while the batch is still Draft (the
        // employee added to the shift, or the check-in keyed in by hand).
        $checkIn = $this->createCheckInFor($employee, $workplace, '2026-08-25 20:00:00', '2026-08-26 06:03:00');

        // The row now looks like it has exactly one check-in, but it is still
        // not bound to it — nothing on the screen would tell the admin.
        $beforeConfirm = $this->actingAs($admin, 'api')
            ->getJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk()
            ->json('data.rows.0');
        $this->assertSame('single', $beforeConfirm['check_in_status']);
        $this->assertNull($beforeConfirm['check_in_id']);

        // Confirm must bind it and compare rather than accept the row as-is:
        // Amazon's 20:19 differs from the new DGF 20:00, so the batch is held
        // back for a decision instead of being confirmed with a row Export
        // would silently drop.
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_DECISIONS_INCOMPLETE')
            ->assertJsonPath('data.unfinished_row_ids', [$rowId]);

        $bound = $this->actingAs($admin, 'api')
            ->getJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk()
            ->json('data.rows.0');
        $this->assertSame($checkIn->id, $bound['check_in_id']);
        $this->assertSame('differs', $bound['compare_status']);
        $this->assertNull($bound['decisions']['check_in_time']);

        // Once resolved the row confirms and reaches the export like any other.
        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'decisions' => ['check_in_time' => 'amazon'],
            ])
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/export")
            ->assertOk();
    }

    public function testConfirmRebindsWhenTheCheckInIsReplacedOnTheSameDate(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn(
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
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $rowId = $batch['rows'][0]['id'];
        $this->assertSame($seeded['checkIn']->id, $batch['rows'][0]['check_in_id']);
        $this->assertSame('matched', $batch['rows'][0]['compare_status']);

        // The employee is moved to a different shift on the same day while the
        // batch is Draft: the old check-in is hard-deleted (nulling the row's
        // FK) and a new one takes its place with different times.
        $seeded['checkIn']->delete();
        $replacement = $this->createCheckInFor(
            $seeded['employee'],
            $workplace,
            '2026-08-25 20:00:00',
            '2026-08-26 06:03:00'
        );

        // Confirming against the check-in that now exists is the whole point:
        // leaving the row on its dead link would confirm a `matched` verdict
        // computed against data that no longer exists.
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_DECISIONS_INCOMPLETE')
            ->assertJsonPath('data.unfinished_row_ids', [$rowId]);

        $rebound = $this->actingAs($admin, 'api')
            ->getJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk()
            ->json('data.rows.0');
        $this->assertSame($replacement->id, $rebound['check_in_id']);
        $this->assertSame('differs', $rebound['compare_status']);
    }

    public function testDecisionPatchResyncsSnapshotSoConfirmDoesNotReopenIt(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
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
                'break_minutes' => 60,
            ]
        );
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $rowId = $batch['rows'][0]['id'];

        // DGF check-in is corrected to the Amazon-punched value after upload
        // but before the admin looks at the row — the serializer shows the
        // live (now-matching) value, so the admin picks `dgf` for it.
        $seeded['checkIn']->update([
            'check_in_time' => '2026-08-25 20:19:00',
            'check_in_time_rounded' => '2026-08-25 20:19:00',
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'decisions' => ['check_in_time' => 'dgf'],
            ])
            ->assertOk();

        // Confirm must accept it on the first try: the PATCH above already
        // resynced snapshot_dgf_check_in to the live value the admin decided
        // against, so Confirm's own live-drift check finds nothing to reopen.
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', ReconciliationBatchStatus::Confirmed->value);
    }

    public function testConfirmBlocksOnDuplicateCheckInAcrossRows(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn(
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
        $batch = $this->upload($admin, [
            [
                'name' => 'Row One Unknown',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
            [
                'name' => 'Row Two Unknown',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        // Two distinct, unrelated punch rows both manually mapped by an admin to
        // the same employee resolve to the same single Check_In on that date.
        foreach ($batch['rows'] as $row) {
            $updated = $this->actingAs($admin, 'api')
                ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$row['id']}", [
                    'user_id' => $seeded['employee']->id,
                ])
                ->assertOk()
                ->json('data');
            $updatedRow = collect($updated['rows'])->firstWhere('id', $row['id']);
            $this->assertSame($seeded['checkIn']->id, $updatedRow['check_in_id']);
        }

        $confirmResponse = $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error', 'RECONCILIATION_DUPLICATE_CHECK_IN');
        $this->assertEqualsCanonicalizing(array_column($batch['rows'], 'id'), $confirmResponse->json('data.row_ids'));
    }

    public function testUnmappingRowRestoresDuplicateNameProtection(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:20:00',
                'punch_out' => '2026-08-26 06:04:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->assertSame('duplicate_external', $batch['rows'][0]['match_status']);
        $this->assertSame('duplicate_external', $batch['rows'][1]['match_status']);
        $rowId = $batch['rows'][0]['id'];

        $mapped = $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'user_id' => $seeded['employee']->id,
            ])
            ->assertOk()
            ->json('data');
        $this->assertSame('exact', $mapped['rows'][0]['match_status']);

        $unmapped = $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'user_id' => null,
            ])
            ->assertOk()
            ->json('data');

        // The sibling row still shares the same normalized name + work date, so
        // this row must fall back to `duplicate_external` again, not silently
        // re-run the name matcher and auto-assign the same employee back.
        $this->assertSame('duplicate_external', $unmapped['rows'][0]['match_status']);
        $this->assertNull($unmapped['rows'][0]['user']);
    }

    public function testCheckInsOptionsHideTestWorkplaceForNormalAdmin(): void
    {
        config()->set('app.show_test_workplaces', false);

        $admin = $this->makeReconciliationAdmin();
        $this->assertFalse((bool) $admin->refresh()->is_test);

        $workplaceA = $this->makeWorkplace('Workplace A');
        $workplaceB = $this->makeWorkplace('Workplace B');
        $testWorkplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Addr',
            'latitude' => 35.0,
            'longitude' => 139.0,
            'is_test' => true,
        ]);

        $first = $this->makeEmployeeWithCheckIn($workplaceA, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $employee = $first['employee'];
        $position = $first['position'];

        foreach ([$workplaceB, $testWorkplace] as $workplace) {
            $template = WorkplaceShift::create([
                'workplace_id' => $workplace->id,
                'start_time' => '08:00:00',
                'end_time' => '12:00:00',
                'break_duration' => 0,
                'check_in_round_type' => 1,
                'check_in_round_minutes' => 15,
                'check_out_round_type' => 1,
                'check_out_round_minutes' => 15,
            ]);
            $shift = Shift::create([
                'workplace_id' => $workplace->id,
                'workplace_shift_id' => $template->id,
                'creator_id' => $employee->id,
                'date' => '2026-08-25',
                'adjusted_date' => '2026-08-25',
                'fixed_total' => 5,
                'temp_total' => 5,
            ]);
            $shiftUser = ShiftUser::create([
                'shift_id' => $shift->id,
                'user_id' => $employee->id,
                'workplace_position_id' => $position->id,
                'status' => ShiftUserStatus::Approved->value,
            ]);
            CheckIn::create([
                'shift_id' => $shift->id,
                'shift_user_id' => $shiftUser->id,
                'user_id' => $employee->id,
                'check_in_time' => '2026-08-25 08:00:00',
                'check_out_time' => '2026-08-25 12:00:00',
                'break_minutes' => 0,
                'work_minutes' => 240,
                'work_minutes_normal' => 240,
                'work_minutes_night' => 0,
                'work_minutes_overtime' => 0,
                'work_minutes_night_overtime' => 0,
            ]);
        }

        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->assertSame('multiple', $batch['rows'][0]['check_in_status']);
        $optionWorkplaceIds = array_column($batch['rows'][0]['check_ins_options'], 'workplace_id');
        sort($optionWorkplaceIds);
        $this->assertSame([$workplaceA->id, $workplaceB->id], $optionWorkplaceIds);
    }

    public function testMultipleCheckInsRequirePick(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $first = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $position = $first['position'];
        $morning = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'break_duration' => 0,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $shift = $first['shift']->replicate();
        $shift->date = '2026-08-25';
        $shift->adjusted_date = '2026-08-25';
        $shift->workplace_shift_id = $morning->id;
        $shift->save();
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $first['employee']->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $first['employee']->id,
            'check_in_time' => '2026-08-25 08:00:00',
            'check_out_time' => '2026-08-25 12:00:00',
            'break_minutes' => 0,
            'work_minutes' => 240,
            'work_minutes_normal' => 240,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->assertSame('multiple', $batch['rows'][0]['check_in_status']);
        $rowId = $batch['rows'][0]['id'];

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertStatus(422);

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$rowId}", [
                'check_in_id' => $first['checkIn']->id,
                'decisions' => [
                    'check_in_time' => 'amazon',
                    'check_out_time' => 'amazon',
                    'break_minutes' => 'dgf',
                ],
            ])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();
    }

    public function testOvernightAdjustedDateMatch(): void
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
            '2026-08-24',
            [],
            '2026-08-25'
        );
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->assertNotNull($batch['rows'][0]['check_in_id']);
    }

    public function testPendingRequestIsFlagged(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        WorkHistoryEditRequest::create([
            'check_in_id' => $seeded['checkIn']->id,
            'workplace_id' => $workplace->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->assertTrue($batch['rows'][0]['has_pending_edit_request']);
    }

    public function testConfirmFreezesLaterPatch(): void
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
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->patchJson("/manage/work-history-reconciliations/{$batch['id']}/rows/{$batch['rows'][0]['id']}", [
                'skipped' => true,
            ])
            ->assertStatus(409);
    }

    public function testNoAttendanceCanConfirmWithoutSkip(): void
    {
        $admin = $this->makeReconciliationAdmin();
        User::factory()->create([
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
        ]);
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->assertSame('none', $batch['rows'][0]['check_in_status']);
        $this->assertNotNull($batch['rows'][0]['user']);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();
    }

    public function testAbsentShiftUserCheckInIsStillAttached(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn(
            $workplace,
            [
                'last_name_kana' => 'ウィジェシンヘ',
                'first_name_kana' => 'サミシカ',
                'short_name' => null,
            ],
            absent: true
        );
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->assertSame($seeded['checkIn']->id, $batch['rows'][0]['check_in_id']);
        $this->assertSame('differs', $batch['rows'][0]['compare_status']);
    }

    public function testSearchUsersRanksOnDutyMatchesAheadOfLimit(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        for ($i = 0; $i < 20; $i++) {
            User::factory()->create([
                'last_name_kana' => 'サトウ',
                'first_name_kana' => 'オフ' . $i,
                'staff_no' => 'S-OFF-' . $i,
            ]);
        }
        $onDuty = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'サトウ',
            'first_name_kana' => 'オン',
            'staff_no' => 'S-ON-1',
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/manage/work-history-reconciliations/users?q=サトウ&work_date=2026-08-25'
        );

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertCount(20, $ids);
        $this->assertSame($onDuty['employee']->id, $ids->first());
    }

    public function testSearchUsersResolvesSelectedKey(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $user = User::factory()->create([
            'first_name' => 'Uploader',
            'last_name' => 'Admin',
            'staff_no' => 'S-KEY-1',
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/manage/work-history-reconciliations/users?key=' . $user->id
        );

        $response->assertOk();
        $this->assertSame($user->id, $response->json('data.0.id'));
        $this->assertCount(1, $response->json('data'));
    }

    public function testSearchUsersReturnsCheckInsOnWorkDate(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'ウィジェシンヘ',
            'first_name_kana' => 'サミシカ',
            'short_name' => null,
            'staff_no' => 'S-RECON-1',
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/manage/work-history-reconciliations/users?q=S-RECON-1&work_date=2026-08-25'
        );

        $response->assertOk();
        $this->assertSame($seeded['employee']->id, $response->json('data.0.id'));
        $this->assertSame($seeded['checkIn']->id, $response->json('data.0.check_ins.0.id'));
    }

    public function testDeleteDraftRemovesBatchAndFile(): void
    {
        Storage::fake();
        $admin = $this->makeReconciliationAdmin();
        $batch = $this->upload($admin, [
            [
                'name' => 'Unknown Person',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $path = ReconciliationBatch::query()->findOrFail($batch['id'])->original_file_path;
        $this->assertTrue(Storage::exists($path));

        $this->actingAs($admin, 'api')
            ->deleteJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk();

        $this->assertNull(ReconciliationBatch::query()->find($batch['id']));
        $this->assertFalse(Storage::exists($path));
    }

    public function testDeleteConfirmedReturns409(): void
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
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->deleteJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertStatus(409);
    }

    public function testBulkDecideAfterMatchedLiveDriftStillConfirms(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn(
            $workplace,
            [
                'last_name_kana' => 'ウィジェシンヘ',
                'first_name_kana' => 'サミシカ',
                'short_name' => null,
            ],
            checkInAttrs: [
                'check_in_time' => '2026-08-25 20:19:00',
                'check_out_time' => '2026-08-26 06:03:00',
                'break_minutes' => 60,
            ]
        );
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);
        $this->assertSame('matched', $batch['rows'][0]['compare_status']);

        $seeded['checkIn']->update([
            'check_in_time' => '2026-08-25 20:10:00',
            'check_in_time_rounded' => '2026-08-25 20:10:00',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/bulk-decide", [
                'side' => 'amazon',
            ])
            ->assertOk()
            ->assertJsonPath('data.rows.0.compare_status', 'differs')
            ->assertJsonPath('data.rows.0.decisions.check_in_time', 'amazon');

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', ReconciliationBatchStatus::Confirmed->value);
    }

    public function testBulkDecideAfterLiveDriftStillConfirms(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
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
                'break_minutes' => 60,
            ]
        );
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $seeded['checkIn']->update([
            'check_in_time' => '2026-08-25 20:10:00',
            'check_in_time_rounded' => '2026-08-25 20:10:00',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/bulk-decide", [
                'side' => 'amazon',
            ])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', ReconciliationBatchStatus::Confirmed->value);
    }

    public function testConfirmedBatchShowsSnapshotDgfNotLive(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn(
            $workplace,
            [
                'last_name_kana' => 'ウィジェシンヘ',
                'first_name_kana' => 'サミシカ',
                'short_name' => null,
            ],
            checkInAttrs: [
                'check_in_time' => '2026-08-25 20:19:00',
                'check_out_time' => '2026-08-26 06:03:00',
                'break_minutes' => 60,
            ]
        );
        $batch = $this->upload($admin, [
            [
                'name' => 'ウィジェシンヘ サミシカ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/work-history-reconciliations/{$batch['id']}/confirm")
            ->assertOk();

        $seeded['checkIn']->update([
            'check_in_time' => '2026-08-25 10:00:00',
            'check_in_time_rounded' => '2026-08-25 10:00:00',
        ]);

        $row = $this->actingAs($admin, 'api')
            ->getJson("/manage/work-history-reconciliations/{$batch['id']}")
            ->assertOk()
            ->json('data.rows.0');

        $this->assertStringContainsString('20:19', (string) $row['dgf_check_in']);
        $this->assertStringNotContainsString('10:00', (string) $row['dgf_check_in']);
    }

    public function testAmbiguousCandidatesIncludeCheckInSummary(): void
    {
        $admin = $this->makeReconciliationAdmin();
        $workplace = $this->makeWorkplace();
        $onDuty = $this->makeEmployeeWithCheckIn($workplace, [
            'last_name_kana' => 'タパ',
            'first_name_kana' => 'ウマ',
            'short_name' => null,
        ]);
        User::factory()->create([
            'last_name_kana' => 'タパ',
            'first_name_kana' => 'アニータ',
            'short_name' => null,
        ]);
        $batch = $this->upload($admin, [
            [
                'name' => 'タパ',
                'date' => '2026-08-25',
                'punch_in' => '2026-08-25 20:19:00',
                'punch_out' => '2026-08-26 06:03:00',
                'break' => 60 / 1440,
            ],
        ]);

        $row = $batch['rows'][0];
        $this->assertSame('ambiguous', $row['match_status']);
        $this->assertNotEmpty($row['candidates']);
        $first = $row['candidates'][0];
        $this->assertSame($onDuty['employee']->id, $first['id']);
        $this->assertNotEmpty($first['check_ins']);
        $this->assertSame($workplace->name, $first['check_ins'][0]['workplace_name']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function createCheckInFor(
        User $employee,
        Workplace $workplace,
        string $checkInTime,
        string $checkOutTime
    ): CheckIn {
        $template = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '21:00:00',
            'end_time' => '06:00:00',
            'break_duration' => 60,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        // The workplace may already have a Staff position from
        // makeEmployeeWithCheckIn(); (workplace_id, name, active) is unique.
        $position = WorkplacePosition::firstOrCreate(['workplace_id' => $workplace->id, 'name' => 'Staff']);
        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $template->id,
            'creator_id' => $employee->id,
            'date' => '2026-08-25',
            'adjusted_date' => '2026-08-25',
            'fixed_total' => 5,
            'temp_total' => 5,
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
            'check_in_time' => $checkInTime,
            'check_out_time' => $checkOutTime,
            'break_minutes' => 60,
            'work_minutes' => 480,
            'work_minutes_normal' => 120,
            'work_minutes_night' => 360,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }

    private function upload($admin, array $rows): array
    {
        $path = sys_get_temp_dir() . '/recon-' . uniqid() . '.xlsx';
        $this->writeAmazonKadouFile($path, $rows);
        $response = $this->actingAs($admin, 'api')->post('/manage/work-history-reconciliations', [
            'source' => 'amazon_kadou',
            'file' => new UploadedFile($path, 'amazon.xlsx', null, null, true),
        ]);
        $response->assertCreated();
        @unlink($path);

        return $response->json('data');
    }
}
