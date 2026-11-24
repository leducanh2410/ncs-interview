<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\Encoding;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ImportCsvTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Shift $shift;

    private WorkplacePosition $position;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');

        $this->manager = User::factory()->create();
        $permission = Permission::create([
            'name' => PermissionName::ShiftImport->value,
            'display_name' => 'Shift Import',
        ]);
        $this->manager->givePermission($permission, null);

        $workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->position = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Staff']);

        $this->shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testImportValidationFailsWithoutFile(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), []);

        $response->assertStatus(422);
        $errors = $response->json('data') ?? [];
        $this->assertArrayHasKey('file', $errors);
        $this->assertArrayHasKey('encoding', $errors);
    }

    public function testImportEmptyCsvReturns400(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\n"),
        ]);

        $response->assertStatus(400);
    }

    public function testImportInvalidStaffNoReturnsErrorData(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,UNKNOWN\n"),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.error_data.0.staff_no', 'UNKNOWN');
    }

    public function testImportForbiddenWithoutPermission(): void
    {
        /** @var User $other */
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,UNKNOWN\n"),
        ]);

        $response->assertStatus(403);
    }

    public function testImportWithShiftImportPermissionOnAnotherWorkplaceReturns403(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        /** @var User $scopedManager */
        $scopedManager = User::factory()->create();
        $scopedManager->givePermission(
            Permission::query()->where('name', PermissionName::ShiftImport->value)->first(),
            $otherWorkplace
        );

        $response = $this->actingAs($scopedManager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,10001\n"),
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', ErrorCode::Forbidden->value);
    }

    public function testImportValidRowsGroupsUsersByPosition(): void
    {
        $first = $this->createMember('10001');
        $second = $this->createMember('10002');

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,10001\nStaff,10002\n"),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.error_data', []);
        $response->assertJsonPath("data.valid_data.{$this->position->id}", [$first->id, $second->id]);
        $response->assertJsonPath("data.users_in_workplace.{$first->id}.id", $first->id);
        $response->assertJsonPath("data.users_in_workplace.{$second->id}.id", $second->id);
    }

    public function testImportGroupsValidRowsUnderDistinctPositions(): void
    {
        $driverPosition = WorkplacePosition::create([
            'workplace_id' => $this->shift->workplace_id,
            'name' => 'Driver',
        ]);
        $staff = $this->createMember('10003');
        $driver = $this->createMember('10004');

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,10003\nDriver,10004\n"),
        ]);

        $response->assertOk();
        $response->assertJsonPath("data.valid_data.{$this->position->id}", [$staff->id]);
        $response->assertJsonPath("data.valid_data.{$driverPosition->id}", [$driver->id]);
    }

    public function testImportExposesAbsenceCountForEachUser(): void
    {
        $member = $this->createMember('10005');

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,10005\n"),
        ]);

        $response->assertOk();
        $response->assertJsonPath("data.users_in_workplace.{$member->id}.absence_count", 0);
    }

    public function testImportLastRowWinsWhenAStaffNoIsRepeated(): void
    {
        $driverPosition = WorkplacePosition::create([
            'workplace_id' => $this->shift->workplace_id,
            'name' => 'Driver',
        ]);
        $member = $this->createMember('10006');

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,10006\nDriver,10006\n"),
        ]);

        $response->assertOk();
        $response->assertJsonMissingPath("data.valid_data.{$this->position->id}");
        $response->assertJsonPath("data.valid_data.{$driverPosition->id}", [$member->id]);
    }

    public function testImportUnknownPositionReturnsErrorDataWithUserName(): void
    {
        $member = $this->createMember('10007');

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nUnknownPosition,10007\n"),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.valid_data', []);
        $response->assertJsonPath('data.error_data.0.staff_no', '10007');
        $response->assertJsonPath('data.error_data.0.user_name', $member->name);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::ShiftCsvInvalidWorkplacePosition->value);
    }

    public function testImportDisabledUserReturnsErrorData(): void
    {
        $member = $this->createMember('10008');
        $member->update(['is_disabled' => true]);

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,10008\n"),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.valid_data', []);
        $response->assertJsonPath('data.error_data.0.staff_no', '10008');
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::ScheduleUserDisabled->value);
    }

    public function testImportUserWithExpiringResidenceCardReturnsErrorData(): void
    {
        $member = $this->createMember('10009');
        // Shift date is 2026-05-10 and the threshold is 3 days, so this card is too close to expiry.
        $member->info->update(['residence_expiry_date_1' => '2026-05-11']);

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,10009\n"),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.valid_data', []);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::ScheduleExpiredResidenceCard->value);
    }

    public function testImportUserWithAnOverlappingShiftReturnsErrorData(): void
    {
        $member = $this->createMember('10010');
        $this->approveOnOverlappingShift($member);

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,10010\n"),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.valid_data', []);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::ScheduleShiftOverlap->value);
    }

    public function testImportReportsValidAndInvalidRowsTogether(): void
    {
        $valid = $this->createMember('10011');
        $this->createMember('10012')->update(['is_disabled' => true]);

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,10011\nStaff,10012\nStaff,MISSING\n"),
        ]);

        $response->assertOk();
        $response->assertJsonPath("data.valid_data.{$this->position->id}", [$valid->id]);
        $response->assertJsonPath('data.error_data.0.staff_no', '10012');
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::ScheduleUserDisabled->value);
        $response->assertJsonPath('data.error_data.1.staff_no', 'MISSING');
        $response->assertJsonPath('data.error_data.1.user_name', null);
        $response->assertJsonPath('data.error_data.1.error_code', ErrorCode::ShiftCsvInvalidStaffNo->value);
    }

    public function testImportIgnoresUsersOutsideTheWorkplace(): void
    {
        User::factory()->create(['staff_no' => '10013']);

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url(), [
            'encoding' => Encoding::UTF8->value,
            'file' => $this->csv("position,staff_no\nStaff,10013\n"),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.valid_data', []);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::ShiftCsvInvalidStaffNo->value);
    }

    /**
     * Creates a workplace member whose papers are all valid, so only the row under
     * test decides whether the import succeeds.
     */
    private function createMember(string $staffNo): User
    {
        /** @var User $member */
        $member = User::factory()->create(['staff_no' => $staffNo]);
        $member->info->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        WorkplaceUser::create(['user_id' => $member->id, 'workplace_id' => $this->shift->workplace_id]);

        return $member->refresh();
    }

    private function approveOnOverlappingShift(User $member): void
    {
        $overlappingWorkplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->shift->workplace_id,
            'start_time' => '10:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $overlappingShift = Shift::query()->create([
            'workplace_id' => $this->shift->workplace_id,
            'workplace_shift_id' => $overlappingWorkplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => $this->shift->date,
            'adjusted_date' => $this->shift->adjusted_date,
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
        ShiftUser::create([
            'shift_id' => $overlappingShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    private function url(): string
    {
        return "/manage/shifts/{$this->shift->id}/import-csv";
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('shifts.csv', $content);
    }
}
