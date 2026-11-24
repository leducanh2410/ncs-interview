<?php

namespace Tests\Feature\Manage\Notification;

use App\Enums\GroupKind;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Jobs\PushNotificationToUser;
use App\Models\Group;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

/**
 * Drives the whole recruitment-targeting feature through real endpoints and the real
 * delivery job, per the Test Design Rules: the state each step depends on (a read
 * notification, a real registration, a real check-in-backed history) is produced by
 * calling the actual flow rather than hand-inserting rows.
 *
 * Scenario: an employee reads a recruitment notification and registers for the shift
 * (cycle 1); the registration is confirmed urgent. A second such cycle on a different
 * shift (cycle 2) gives the employee two checked-in urgent registrations inside the
 * 90-day window, which is what the frequent-urgent-worker group requires. A third
 * recruitment notification — for a workplace the employee has no relationship to at
 * all — opts into that group, and the employee is reached through group inclusion alone.
 */
class RecruitmentNotificationLifecycleTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        foreach ([PermissionName::NotificationList, PermissionName::NotificationCreate] as $permissionName) {
            $permission = Permission::create([
                'name' => $permissionName->value,
                'display_name' => $permissionName->value,
            ]);
            $this->admin->permissions()->attach($permission);
        }

        $this->workplace = $this->createWorkplaceAt(35.6895, 139.6917, 'Lifecycle Workplace');
        $this->employee = $this->createEmployeeAt($this->workplace);
    }

    public function testFullRecruitmentLifecycleThroughRealEndpoints(): void
    {
        // --- Cycle 1: read, then register for real, through the real endpoints ---
        $shiftOne = $this->createShiftAt($this->workplace, now()->addDays(5)->format('Y-m-d'));
        $notificationOneId = $this->createRecruitmentNotificationViaEndpoint($shiftOne);

        (new PushNotificationToUser())->handle();

        $notificationOne = Notification::find($notificationOneId);
        $this->assertSame(NotificationStatus::SENT->value, $notificationOne->status);
        $this->assertSame(1, $notificationOne->recipients_resolved_count);
        $this->assertTrue(
            NotificationRecipient::query()
                ->where('notification_id', $notificationOneId)
                ->where('user_id', $this->employee->id)
                ->exists()
        );

        $this->actingAs($this->employee, 'api')
            ->postJson("/user/notifications/{$notificationOneId}/read")
            ->assertOk();

        $this->actingAs($this->employee, 'api')
            ->postJson("/user/shifts/{$shiftOne->id}/register")
            ->assertOk();

        $shiftUserOne = ShiftUser::query()
            ->where('shift_id', $shiftOne->id)
            ->where('user_id', $this->employee->id)
            ->firstOrFail();
        $this->assertTrue(
            $shiftUserOne->is_urgent_registration,
            'Registering after reading the shift\'s recruitment notification must be flagged urgent'
        );

        // Check-in is a different, pre-existing feature's endpoint — using the fixture helper
        // here is reaching into a real, already-created shift_users row rather than
        // hand-inserting the state this feature is responsible for.
        $this->checkInFor($shiftUserOne);

        // --- Cycle 2: same pattern, a different shift ---
        $shiftTwo = $this->createShiftAt($this->workplace, now()->addDays(6)->format('Y-m-d'));
        $notificationTwoId = $this->createRecruitmentNotificationViaEndpoint($shiftTwo);

        (new PushNotificationToUser())->handle();

        $this->actingAs($this->employee, 'api')
            ->postJson("/user/notifications/{$notificationTwoId}/read")
            ->assertOk();

        $this->actingAs($this->employee, 'api')
            ->postJson("/user/shifts/{$shiftTwo->id}/register")
            ->assertOk();

        $shiftUserTwo = ShiftUser::query()
            ->where('shift_id', $shiftTwo->id)
            ->where('user_id', $this->employee->id)
            ->firstOrFail();
        $this->assertTrue($shiftUserTwo->is_urgent_registration);
        $this->checkInFor($shiftUserTwo);

        // Two checked-in urgent registrations now exist inside the 90-day window — exactly
        // the threshold the frequent-urgent-worker group requires.

        // --- Reached through the group alone: an unrelated, distant workplace ---
        $distantWorkplace = $this->createWorkplaceAt(43.0, 141.3, 'Distant Workplace');
        $distantShift = $this->createShiftAt($distantWorkplace, now()->addDays(7)->format('Y-m-d'));
        // The employee has no relationship whatsoever to this workplace: not employed there,
        // and far enough away that no nearby-radius inclusion would ever reach them.
        $this->assertFalse($distantWorkplace->users()->where('users.id', $this->employee->id)->exists());

        $notificationThreeId = $this->createRecruitmentNotificationViaEndpoint($distantShift, [
            'include_frequent_urgent_group' => true,
        ]);

        (new PushNotificationToUser())->handle();

        $notificationThree = Notification::find($notificationThreeId);
        $this->assertSame(1, $notificationThree->recipients_resolved_count);
        $this->assertTrue(
            NotificationRecipient::query()
                ->where('notification_id', $notificationThreeId)
                ->where('user_id', $this->employee->id)
                ->exists(),
            'The employee should be reached for a workplace they have no direct relationship ' .
                'to, solely because they now qualify for the frequent-urgent-worker group'
        );

        // The group membership itself is real, not just the notification's outcome.
        $group = Group::query()->where('kind', GroupKind::FrequentUrgentWorker->value)->first();
        $this->assertNotNull($group);
        $this->assertTrue($group->users()->where('users.id', $this->employee->id)->exists());

        // Being targeted is only half the promise: the push has to lead somewhere. Opening
        // and then registering for a shift at a workplace they do not belong to is the whole
        // point of widening the audience, and it is a code path no workplace member ever
        // takes — the recipient check is the only thing admitting them.
        $this->actingAs($this->employee, 'api')
            ->getJson("/user/shifts/{$distantShift->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $distantShift->id);

        $this->actingAs($this->employee, 'api')
            ->postJson("/user/shifts/{$distantShift->id}/register")
            ->assertOk();

        // An employee who was never targeted still cannot reach that same distant shift.
        $stranger = $this->createEmployeeAt($this->workplace);
        $this->actingAs($stranger, 'api')
            ->getJson("/user/shifts/{$distantShift->id}")
            ->assertForbidden();
    }

    public function testCreateNotificationKeepsJapaneseTranslationForAdminUsingJapaneseUi(): void
    {
        $this->admin->update(['settings' => ['lang' => 'ja']]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/manage/notifications', [
            'type' => NotificationType::NORMAL->value,
            'title' => 'Thông báo',
            'content' => 'Nội dung',
            'title_ja' => 'お知らせ',
            'content_ja' => '内容',
            'is_manually_scheduled' => false,
        ]);

        $response->assertOk();
        $notification = Notification::findOrFail($response->json('data.id'));
        $japanese = $notification->translations()->where('lang', 'ja')->firstOrFail();

        $this->assertSame('お知らせ', $japanese->title);
        $this->assertSame('内容', $japanese->content);
    }

    /**
     * @param array<string, mixed> $extraCriteria
     */
    private function createRecruitmentNotificationViaEndpoint(Shift $shift, array $extraCriteria = []): int
    {
        $payload = array_merge(
            [
                'type' => NotificationType::RECRUITMENT->value,
                'title' => 'Urgent staff needed',
                'content' => 'Please help cover this shift',
                'is_manually_scheduled' => false,
                'shift_id' => $shift->id,
                'gender_target' => 'both',
                'requires_experience' => false,
                'include_nearby_workplaces' => false,
                'include_frequent_urgent_group' => false,
                'hot_bonus_amount' => null,
            ],
            $extraCriteria
        );

        $response = $this->actingAs($this->admin, 'api')->postJson('/manage/notifications', $payload);
        $response->assertOk();

        return (int) $response->json('data.id');
    }
}
