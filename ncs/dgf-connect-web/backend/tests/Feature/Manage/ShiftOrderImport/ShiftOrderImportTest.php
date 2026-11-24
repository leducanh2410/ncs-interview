<?php

namespace Tests\Feature\Manage\ShiftOrderImport;

use App\Actions\Manage\Shift\CreateShiftAction;
use App\Actions\Manage\ShiftOrderImport\ConfirmShiftOrderImportAction;
use App\Enums\ErrorCode;
use App\Enums\ImportBatchStatus;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\ImportBatch;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftPositionTotal;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsShiftOrderWorkbook;
use Tests\TestCase;

class ShiftOrderImportTest extends TestCase
{
    use BuildsShiftOrderWorkbook, RefreshDatabase;

    private const OCTOBER_2026_SERIAL = 46296;

    private User $manager;

    private Workplace $workplace;

    private WorkplaceShift $nightShift;

    private WorkplacePosition $position;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 10:00:00');
        Storage::fake();

        $this->manager = User::factory()->create();
        $this->manager->givePermission($this->permission(), null);

        $this->workplace = Workplace::create([
            'name' => 'Musashino',
            'address' => 'Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->nightShift = $this->createWorkplaceShift('22:00:00', '06:00:00');
        $this->position = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Position A',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testPreviewRequiresPermission(): void
    {
        /** @var User $outsider */
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($this->defaultRows()),
        ]);

        $response->assertStatus(403);
    }

    public function testPreviewValidationFailsWithoutFile(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson($this->previewUrl(), []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('file', $response->json('data') ?? []);
    }

    public function testPreviewReturnsNewEntriesAndStoresPendingBatch(): void
    {
        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($this->defaultRows()),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.month', '2026-10');
        $response->assertJsonPath('data.preview.summary.new_count', 2);
        $response->assertJsonPath('data.preview.summary.diff_count', 0);
        $response->assertJsonPath('data.preview.workplaces.0.workplace_id', $this->workplace->id);
        $response->assertJsonPath('data.preview.workplaces.0.shifts.0.days.0.date', '2026-10-01');
        $response->assertJsonPath('data.preview.workplaces.0.shifts.0.days.0.fixed_total', 5);
        $response->assertJsonPath('data.preview.workplaces.0.shifts.0.days.0.spot_total', 2);
        $response->assertJsonPath('data.preview.workplaces.0.shifts.0.days.0.status', 'new');

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(ImportBatchStatus::Pending, $batch->status);
        $this->assertCount(2, $batch->meta['plan']);
        Storage::assertExists($batch->file_path);
        $this->assertDatabaseCount('shifts', 0);
    }

    public function testPreviewRejectsUnknownWorkplaceId(): void
    {
        $rows = $this->defaultRows();
        $rows[2][2] = 999999;

        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($rows),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::ShiftOrderFileHasErrors->value);
        $response->assertJsonPath('data.errors.0.code', 'workplace_not_found');
        $response->assertJsonPath('data.errors.0.cell', 'C3');
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function testPreviewRejectsUnknownPositionName(): void
    {
        $rows = $this->defaultRows();
        $rows[4][3] = 'Unknown position';

        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($rows),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', 'position_not_found');
        $response->assertJsonPath('data.errors.0.value', 'Unknown position');
    }

    public function testPreviewAcceptsSpotPositionsWithoutMatchingWorkplacePosition(): void
    {
        $rows = $this->defaultRows();
        $rows[5][3] = 'スポット';

        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($rows),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.preview.workplaces.0.shifts.0.days.0.spot_total', 2);
    }

    public function testPreviewRejectsPositionTotalsThatDoNotMatchShiftTotal(): void
    {
        $rows = $this->defaultRows();
        $rows[6][4] = 6;

        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($rows),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', 'total_mismatch');
        $response->assertJsonPath('data.errors.0.cell', 'E7');
        $response->assertJsonPath('data.errors.0.shift_total', 6);
        $response->assertJsonPath('data.errors.0.positions_total', 5);
    }

    public function testPreviewRejectsShiftTimeMissingFromWorkplace(): void
    {
        $rows = $this->defaultRows();
        $rows[6][3] = '05:00〜09:00';

        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($rows),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', 'workplace_shift_not_found');
        $response->assertJsonPath('data.errors.0.value', '05:00〜09:00');
    }

    public function testPreviewRejectsInactiveWorkplaceShift(): void
    {
        $this->nightShift->update(['is_active' => false]);

        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($this->defaultRows()),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', 'workplace_shift_not_found');
    }

    public function testPreviewRejectsPastDates(): void
    {
        Carbon::setTestNow('2026-10-02 10:00:00');

        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($this->defaultRows()),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', 'past_date');
        $response->assertJsonPath('data.errors.0.date', '2026-10-01');
    }

    public function testPreviewRejectsWorkplaceTheUserCannotImportInto(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        /** @var User $scoped */
        $scoped = User::factory()->create();
        $scoped->givePermission($this->permission(), $otherWorkplace);

        $response = $this->actingAs($scoped, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($this->defaultRows()),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', 'workplace_forbidden');
    }

    public function testConfirmCreatesShiftsWithPositionTotals(): void
    {
        $batchId = $this->preview();

        $response = $this->actingAs($this->manager, 'api')->postJson($this->confirmUrl($batchId), ['decisions' => []]);

        $response->assertOk();
        $response->assertJsonPath('data.created_count', 2);
        $response->assertJsonPath('data.updated_count', 0);

        $first = Shift::query()->where('date', '2026-10-01')->firstOrFail();
        $this->assertSame(5, $first->fixed_total);
        $this->assertSame(0, $first->temp_total);
        $this->assertSame('2026-10-01', $first->adjusted_date);
        $this->assertDatabaseHas('shift_position_totals', [
            'shift_id' => $first->id,
            'workplace_position_id' => $this->position->id,
            'fixed_total' => 3,
            'temp_total' => 0,
        ]);

        $second = Shift::query()->where('date', '2026-10-03')->firstOrFail();
        $this->assertSame(1, $second->fixed_total);

        $batch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame(ImportBatchStatus::Completed, $batch->status);
        $this->assertSame(2, $batch->created_count);
    }

    public function testPreviewBreaksTheAssignedPeopleDownPerPosition(): void
    {
        $this->importAndConfirm($this->defaultRows());
        $shift = Shift::query()->where('date', '2026-10-01')->firstOrFail();
        $secondPosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Position B',
        ]);
        foreach ([$this->position->id, $this->position->id, $secondPosition->id] as $positionId) {
            ShiftUser::query()->create([
                'shift_id' => $shift->id,
                'user_id' => $this->createMember()->id,
                'workplace_position_id' => $positionId,
                'is_fixed' => true,
                'status' => ShiftUserStatus::Approved,
            ]);
        }

        $batchId = $this->preview();
        $response = $this->actingAs($this->manager, 'api')->getJson($this->showUrl($batchId));

        $response->assertOk();
        $day = $response->json('data.preview.workplaces.0.shifts.0.days.0');
        $this->assertSame(3, $day['existing']['assigned_count']);
        $this->assertSame(0, $day['existing']['unassigned_position_count']);

        $positions = collect($day['existing']['positions'])->keyBy('workplace_position_id');
        $this->assertSame(2, $positions[$this->position->id]['assigned_count']);
        $this->assertSame(3, $positions[$this->position->id]['fixed_total']);
        $this->assertSame(1, $positions[$secondPosition->id]['assigned_count']);
        $this->assertSame(0, $positions[$secondPosition->id]['fixed_total']);
        $this->assertSame('match', $day['status']);
    }

    public function testShiftDetailExposesTheImportedPositionTargets(): void
    {
        $this->importAndConfirm($this->defaultRows());
        $shift = Shift::query()->where('date', '2026-10-01')->firstOrFail();
        $this->manager->givePermission(
            Permission::query()->firstOrCreate(
                ['name' => PermissionName::ShiftList->value],
                ['display_name' => PermissionName::ShiftList->value]
            ),
            null
        );

        $response = $this->actingAs($this->manager, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();
        $response->assertJsonPath('data.position_totals.0.workplace_position_id', $this->position->id);
        $response->assertJsonPath('data.position_totals.0.fixed_total', 3);
    }

    public function testConfirmIsRejectedForAnAlreadyCompletedBatch(): void
    {
        $batchId = $this->preview();
        $this->actingAs($this->manager, 'api')
            ->postJson($this->confirmUrl($batchId), ['decisions' => []])
            ->assertOk();

        $response = $this->actingAs($this->manager, 'api')->postJson($this->confirmUrl($batchId), ['decisions' => []]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::ShiftOrderBatchNotPending->value);
    }

    public function testSecondImportShowsDifferenceAndRequiresADecision(): void
    {
        $this->importAndConfirm($this->defaultRows());

        $changed = $this->defaultRows();
        $changed[4][4] = 5;
        $changed[6][4] = 7;
        $batchId = $this->preview($changed);

        $preview = $this->actingAs($this->manager, 'api')->getJson($this->showUrl($batchId));
        $preview->assertOk();
        $preview->assertJsonPath('data.preview.summary.diff_count', 1);
        $preview->assertJsonPath('data.preview.summary.match_count', 1);
        $preview->assertJsonPath('data.preview.workplaces.0.shifts.0.days.0.existing.fixed_total', 5);
        $preview->assertJsonPath('data.preview.workplaces.0.shifts.0.days.0.fixed_total', 7);

        $missing = $this->actingAs($this->manager, 'api')->postJson($this->confirmUrl($batchId), ['decisions' => []]);
        $missing->assertStatus(422);
        $missing->assertJsonPath('data.errors.0.code', 'decision_required');
        $missing->assertJsonPath('data.errors.0.date', '2026-10-01');
        $this->assertSame(5, Shift::query()->where('date', '2026-10-01')->firstOrFail()->fixed_total);
    }

    public function testConfirmKeepsSystemValuesWhenTheAdminChoosesSystem(): void
    {
        $this->importAndConfirm($this->defaultRows());

        $changed = $this->defaultRows();
        $changed[4][4] = 5;
        $changed[6][4] = 7;
        $batchId = $this->preview($changed);

        $response = $this->actingAs($this->manager, 'api')->postJson($this->confirmUrl($batchId), [
            'decisions' => [
                [
                    'workplace_shift_id' => $this->nightShift->id,
                    'date' => '2026-10-01',
                    'source' => ConfirmShiftOrderImportAction::SOURCE_SYSTEM,
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.skipped_count', 1);

        $shift = Shift::query()->where('date', '2026-10-01')->firstOrFail();
        $this->assertSame(5, $shift->fixed_total);
        $this->assertDatabaseHas('shift_position_totals', [
            'shift_id' => $shift->id,
            'workplace_position_id' => $this->position->id,
            'fixed_total' => 3,
        ]);
    }

    public function testConfirmAppliesFileValuesAndKeepsTempTotalAndAssignedUsers(): void
    {
        $this->importAndConfirm($this->defaultRows());

        $shift = Shift::query()->where('date', '2026-10-01')->firstOrFail();
        $shift->update(['temp_total' => 4, 'max_registered_temp' => 6]);
        $member = $this->createMember();
        ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => true,
            'status' => ShiftUserStatus::Approved,
        ]);

        $changed = $this->defaultRows();
        $changed[4][4] = 5;
        $changed[6][4] = 7;
        $batchId = $this->preview($changed);

        $response = $this->actingAs($this->manager, 'api')->postJson($this->confirmUrl($batchId), [
            'decisions' => [
                [
                    'workplace_shift_id' => $this->nightShift->id,
                    'date' => '2026-10-01',
                    'source' => ConfirmShiftOrderImportAction::SOURCE_FILE,
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.updated_count', 1);
        $response->assertJsonPath('data.unchanged_count', 1);

        $shift->refresh();
        $this->assertSame(7, $shift->fixed_total);
        $this->assertSame(4, $shift->temp_total);
        $this->assertSame(6, $shift->max_registered_temp);
        $this->assertDatabaseHas('shift_position_totals', [
            'shift_id' => $shift->id,
            'workplace_position_id' => $this->position->id,
            'fixed_total' => 5,
        ]);
        $this->assertDatabaseHas('shift_users', ['shift_id' => $shift->id, 'user_id' => $member->id]);
    }

    public function testConfirmRemovesPositionTotalsThatLeftTheFile(): void
    {
        $secondPosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Position B',
        ]);

        $withTwoPositions = $this->defaultRows();
        array_splice($withTwoPositions, 5, 0, [[null, null, null, 'Position B', 1, 0, 0]]);
        $withTwoPositions[7][4] = 6;
        $this->importAndConfirm($withTwoPositions);

        $shift = Shift::query()->where('date', '2026-10-01')->firstOrFail();
        $this->assertDatabaseHas('shift_position_totals', [
            'shift_id' => $shift->id,
            'workplace_position_id' => $secondPosition->id,
        ]);

        $batchId = $this->preview($this->defaultRows());
        $this->actingAs($this->manager, 'api')
            ->postJson($this->confirmUrl($batchId), [
                'decisions' => [
                    [
                        'workplace_shift_id' => $this->nightShift->id,
                        'date' => '2026-10-01',
                        'source' => ConfirmShiftOrderImportAction::SOURCE_FILE,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('shift_position_totals', [
            'shift_id' => $shift->id,
            'workplace_position_id' => $secondPosition->id,
        ]);
    }

    public function testUpdateMonthRecalculatesThePlan(): void
    {
        $batchId = $this->preview();

        $response = $this->actingAs($this->manager, 'api')->postJson($this->monthUrl($batchId), [
            'month' => '2026-11',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.month', '2026-11');
        $response->assertJsonPath('data.preview.workplaces.0.shifts.0.days.0.date', '2026-11-01');
        $this->assertSame('2026-11-01', ImportBatch::query()->findOrFail($batchId)->meta['plan'][0]['date']);
    }

    public function testAnotherAdminCannotOpenSomeoneElsesPendingBatch(): void
    {
        $batchId = $this->preview();
        /** @var User $otherManager */
        $otherManager = User::factory()->create();
        $otherManager->givePermission($this->permission(), null);

        $response = $this->actingAs($otherManager, 'api')->getJson($this->showUrl($batchId));

        $response->assertStatus(404);
    }

    public function testConfirmRollsBackEverythingWhenShiftCreationFails(): void
    {
        $batchId = $this->preview();
        $this->app->bind(
            CreateShiftAction::class,
            fn() => new class extends CreateShiftAction {
                private int $calls = 0;

                public function __invoke(
                    WorkplaceShift $workplaceShift,
                    string $date,
                    int $creatorId,
                    int $fixedTotal,
                    int $tempTotal,
                    ?int $maxRegisteredTemp,
                    ?int $breakMinutes
                ): Shift {
                    $this->calls++;
                    if ($this->calls > 1) {
                        throw new RuntimeException('storage failure');
                    }

                    return parent::__invoke(
                        $workplaceShift,
                        $date,
                        $creatorId,
                        $fixedTotal,
                        $tempTotal,
                        $maxRegisteredTemp,
                        $breakMinutes
                    );
                }
            }
        );

        $response = $this->actingAs($this->manager, 'api')->postJson($this->confirmUrl($batchId), ['decisions' => []]);

        $response->assertStatus(500);
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('shift_position_totals', 0);
        $this->assertSame(ImportBatchStatus::Pending, ImportBatch::query()->findOrFail($batchId)->status);
    }

    public function testPreviewRejectsANegativeCellEvenWhenTheShiftTotalMatches(): void
    {
        $rows = $this->defaultRows();
        $rows[4][4] = -1;
        $rows[5][4] = 6;

        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($rows),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', 'negative_total');
        $response->assertJsonPath('data.errors.0.cell', 'E5');
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function testPreviewRejectsTwoRowsResolvingToTheSamePosition(): void
    {
        $rows = $this->defaultRows();
        array_splice($rows, 5, 0, [[null, null, null, 'position  a', 1, 0, 0]]);

        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($rows),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', 'duplicate_position');
        $response->assertJsonPath('data.errors.0.cell', 'D6');
    }

    public function testReimportingWithZeroQuantitiesClearsTheShiftsAlreadyCreated(): void
    {
        $this->importAndConfirm($this->defaultRows());
        $first = Shift::query()->where('date', '2026-10-01')->firstOrFail();
        $this->assertSame(5, $first->fixed_total);

        $cancelled = $this->defaultRows();
        foreach ([4, 5, 6] as $row) {
            $cancelled[$row][4] = 0;
            $cancelled[$row][6] = 0;
        }

        $batchId = $this->preview($cancelled);
        $preview = $this->actingAs($this->manager, 'api')->getJson($this->showUrl($batchId));

        $preview->assertOk();
        $preview->assertJsonPath('data.preview.summary.diff_count', 2);
        $preview->assertJsonPath('data.preview.summary.new_count', 0);

        $this->actingAs($this->manager, 'api')
            ->postJson($this->confirmUrl($batchId), [
                'decisions' => [
                    [
                        'workplace_shift_id' => $this->nightShift->id,
                        'date' => '2026-10-01',
                        'source' => ConfirmShiftOrderImportAction::SOURCE_FILE,
                    ],
                    [
                        'workplace_shift_id' => $this->nightShift->id,
                        'date' => '2026-10-03',
                        'source' => ConfirmShiftOrderImportAction::SOURCE_FILE,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2);

        $this->assertSame(0, $first->refresh()->fixed_total);
        $this->assertDatabaseMissing('shift_position_totals', ['shift_id' => $first->id]);
    }

    public function testConfirmDoesNotRecreateAShiftDeletedWhileTheZeroQuantityPreviewWasOpen(): void
    {
        $this->importAndConfirm($this->defaultRows());
        $created = Shift::query()->where('date', '2026-10-01')->firstOrFail();

        $cancelled = $this->defaultRows();
        foreach ([4, 5, 6] as $row) {
            $cancelled[$row][4] = 0;
            $cancelled[$row][6] = 0;
        }
        $batchId = $this->preview($cancelled);

        $this->manager->givePermission(
            Permission::query()->where('name', PermissionName::ShiftDelete->value)->firstOrFail(),
            null
        );
        $this->actingAs($this->manager, 'api')
            ->deleteJson("/manage/shifts/{$created->id}")
            ->assertOk();
        $this->assertDatabaseMissing('shifts', ['id' => $created->id]);

        $response = $this->actingAs($this->manager, 'api')->postJson($this->confirmUrl($batchId), [
            'decisions' => [
                [
                    'workplace_shift_id' => $this->nightShift->id,
                    'date' => '2026-10-03',
                    'source' => ConfirmShiftOrderImportAction::SOURCE_FILE,
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.created_count', 0);
        $response->assertJsonPath('data.unchanged_count', 1);
        $response->assertJsonPath('data.updated_count', 1);
        $this->assertDatabaseMissing('shifts', ['date' => '2026-10-01']);
        $this->assertSame(0, Shift::query()->where('date', '2026-10-03')->firstOrFail()->fixed_total);
    }

    public function testZeroQuantitiesAreIgnoredWhileNoShiftExistsYet(): void
    {
        $zeroed = $this->defaultRows();
        foreach ([4, 5, 6] as $row) {
            $zeroed[$row][4] = 0;
            $zeroed[$row][6] = 0;
        }

        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($zeroed),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.code', 'no_shift_order');
    }

    public function testConfirmRejectsAPlanThatTurnedPastDatedWhileTheTabStayedOpen(): void
    {
        $batchId = $this->preview();

        Carbon::setTestNow('2026-10-02 10:00:00');

        $response = $this->actingAs($this->manager, 'api')->postJson($this->confirmUrl($batchId), ['decisions' => []]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::ShiftOrderFileHasErrors->value);
        $response->assertJsonPath('data.errors.0.code', 'past_date');
        $response->assertJsonPath('data.errors.0.date', '2026-10-01');
        $this->assertDatabaseCount('shifts', 0);
        $this->assertSame(ImportBatchStatus::Pending, ImportBatch::query()->findOrFail($batchId)->status);
    }

    public function testUpdateMonthRejectsAMonthOutsideOneToTwelve(): void
    {
        $batchId = $this->preview();

        $response = $this->actingAs($this->manager, 'api')->postJson($this->monthUrl($batchId), [
            'month' => '2026-13',
        ]);

        $response->assertStatus(422);
        $this->assertSame('2026-10', ImportBatch::query()->findOrFail($batchId)->meta['month']);
    }

    public function testPreviewResponseKeepsThePlanOutOfTheBatchPayload(): void
    {
        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($this->defaultRows()),
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('meta', $response->json('data.batch'));
    }

    private function permission(): Permission
    {
        return Permission::query()->where('name', PermissionName::ShiftImportOrder->value)->firstOrFail();
    }

    private function createWorkplaceShift(string $startTime, string $endTime): WorkplaceShift
    {
        return WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    private function createMember(): User
    {
        /** @var User $member */
        $member = User::factory()->create();
        WorkplaceUser::create(['workplace_id' => $this->workplace->id, 'user_id' => $member->id]);

        return $member;
    }

    /**
     * @param array<int, array<int, mixed>>|null $rows
     */
    private function preview(?array $rows = null): int
    {
        $response = $this->actingAs($this->manager, 'api')->post($this->previewUrl(), [
            'file' => $this->workbook($rows ?? $this->defaultRows()),
        ]);
        $response->assertOk();

        return (int) $response->json('data.batch.id');
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function importAndConfirm(array $rows): void
    {
        $batchId = $this->preview($rows);
        $this->actingAs($this->manager, 'api')
            ->postJson($this->confirmUrl($batchId), ['decisions' => []])
            ->assertOk();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function defaultRows(): array
    {
        return [
            [null, null, 'ID', self::OCTOBER_2026_SERIAL, '1日', '2日', '3日'],
            [null, '合計Key', null, null, '木', '金', '土'],
            [null, null, $this->workplace->id, 'Musashino', '1日', '2日', '3日'],
            [null, null, null, null, '木', '金', '土'],
            [null, null, null, 'Position A', 3, 0, 1],
            [null, null, null, 'spot', 2, 0, 0],
            [null, null, null, '22:00〜30:00', 5, 0, 1],
            [null, null, null, null, null, null, null],
            [null, '注文合計(人)', null, null, 5, 0, 1],
        ];
    }

    private function previewUrl(): string
    {
        return '/manage/shifts/import-order/preview';
    }

    private function showUrl(int $batchId): string
    {
        return "/manage/shifts/import-order/{$batchId}";
    }

    private function monthUrl(int $batchId): string
    {
        return "/manage/shifts/import-order/{$batchId}/month";
    }

    private function confirmUrl(int $batchId): string
    {
        return "/manage/shifts/import-order/{$batchId}/confirm";
    }
}
