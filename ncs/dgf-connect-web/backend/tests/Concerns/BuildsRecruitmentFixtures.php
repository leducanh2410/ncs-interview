<?php

namespace Tests\Concerns;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Models\CheckIn;
use App\Models\Notification;
use App\Models\NotificationReadState;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\ValueObjects\RecruitmentCriteria;
use Illuminate\Support\Carbon;

/**
 * Fixtures for recruitment targeting tests.
 *
 * There are no factories for Workplace/Shift in this project, so the explicit create()
 * calls other tests use are gathered here instead of being repeated per test class.
 */
trait BuildsRecruitmentFixtures
{
    protected function createWorkplaceAt(
        float $latitude = 35.6895,
        float $longitude = 139.6917,
        string $name = 'Workplace'
    ): Workplace {
        return Workplace::create([
            'name' => $name,
            'address' => $name . ' address',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    protected function createShiftAt(
        Workplace $workplace,
        ?string $date = null,
        string $startTime = '09:00:00',
        string $endTime = '18:00:00',
        ?User $creator = null
    ): Shift {
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $date ??= now()->addDay()->format('Y-m-d');

        return Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => ($creator ?? User::factory()->create())->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
    }

    /**
     * An employee attached to a workplace, optionally with a recorded gender.
     *
     * Gender is left unset by default because it is nullable in production and the
     * targeting rules treat "unrecorded" as its own case rather than as a value.
     *
     * paper_status is set to Approved and staff_no is populated so the fixture can drive
     * real user-facing endpoints (registration, notification read) gated by
     * CheckUserPaperStatus and ValidatesShiftUser::checkUserExpiration respectively. Document
     * expiry dates are explicitly cleared: UserFactory attaches a UserInfo with Faker-random
     * expiry dates that land in the past about half the time, which would otherwise make
     * shift registration fail on an unrelated document-expiry check depending on random luck.
     */
    protected function createEmployeeAt(
        Workplace $workplace,
        ?string $gender = null,
        string $paperStatus = UserPaperStatus::Approved->value
    ): User {
        $user = User::factory()->create(['paper_status' => $paperStatus]);
        $user->update(['staff_no' => "STAFF-{$user->id}"]);
        UserInfo::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'gender' => $gender,
                'residence_expiry_date_1' => null,
                'residence_expiry_date_2' => null,
                'residence_expiry_date_3' => null,
                'passport_expiry_date' => null,
                'student_card_expiry_date' => null,
            ]
        );
        $workplace->users()->syncWithoutDetaching([$user->id]);

        return $user->refresh();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    protected function createRecruitmentNotification(
        Shift $shift,
        array $criteria = [],
        NotificationStatus $status = NotificationStatus::PENDING
    ): Notification {
        return Notification::create([
            'type' => NotificationType::RECRUITMENT->value,
            'title' => 'Urgent staff needed',
            'content' => 'Please help cover this shift',
            'status' => $status,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'user_id' => null,
            'data' => RecruitmentCriteria::fromArray(
                array_merge($criteria, [
                    'shift_id' => $shift->id,
                    'workplace_id' => $shift->workplace_id,
                ])
            )->toArray(),
        ]);
    }

    protected function markNotificationRead(Notification $notification, User $user, ?Carbon $readAt = null): void
    {
        NotificationReadState::create([
            'notification_id' => $notification->id,
            'user_id' => $user->id,
            'read_at' => $readAt ?? now(),
        ]);
    }

    /**
     * Somebody else placing the employee on the shift (admin assignment, CSV import).
     *
     * Deliberately runs with whatever auth context the test already has — which is normally
     * none — because that is what a bulk assignment looks like. The urgent-registration flag
     * does not distinguish the two paths (requirement 1.7); they are kept separate here so a
     * test can still state which one it is exercising.
     */
    protected function assignToShift(
        Shift $shift,
        User $user,
        string $status = ShiftUserStatus::Approved->value
    ): ShiftUser {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'status' => $status,
            'is_fixed' => false,
        ]);
    }

    /**
     * The employee putting themselves on the shift, as POST /user/shifts/{shift}/register
     * does — authenticated as the employee whose row is being created.
     */
    protected function selfRegisterForShift(
        Shift $shift,
        User $user,
        string $status = ShiftUserStatus::Pending->value
    ): ShiftUser {
        $this->actingAs($user, 'api');

        return $this->assignToShift($shift, $user, $status);
    }

    /**
     * A completed attendance for an existing assignment.
     *
     * Several rules turn on whether the employee actually turned up rather than on the
     * assignment alone, so tests need to express those two states separately.
     */
    protected function checkInFor(ShiftUser $shiftUser): CheckIn
    {
        return CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shiftUser->shift_id,
            'user_id' => $shiftUser->user_id,
            'check_in_time' => now()->subHours(8),
            'check_out_time' => now(),
        ]);
    }
}
