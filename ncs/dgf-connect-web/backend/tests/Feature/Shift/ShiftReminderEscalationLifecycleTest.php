<?php

namespace Tests\Feature\Shift;

use App\Enums\ChatConversationType;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Jobs\ShiftAdminAlert;
use App\Jobs\ShiftReminder;
use App\Models\ChatConversation;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

/**
 * Drives the escalation feature end to end through real registration, approval,
 * confirmation, and check-in endpoints rather than fixture inserts, per the project's
 * "reach state through real flows" test design rule.
 */
class ShiftReminderEscalationLifecycleTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplacePosition $workplacePosition;

    private User $shiftAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'work.reminder.confirmation.final_before_minute' => 180,
            'work.reminder.unconfirmed_alert.before_minute' => 120,
            'work.reminder.missing_check_in_alert.after_minute' => 10,
        ]);

        $this->workplace = $this->createWorkplaceAt(name: 'Escalation Workplace');
        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);

        $shiftRole = Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
        $shiftRole
            ->permissions()
            ->attach([
                Permission::create(['name' => PermissionName::ShiftEdit->value, 'display_name' => 'Shift Edit'])->id,
                Permission::create(['name' => PermissionName::ShiftCheckIn->value, 'display_name' => 'Shift Check In'])
                    ->id,
            ]);
        $this->shiftAdmin = User::factory()->create();
        $this->shiftAdmin->addRole($shiftRole, $this->workplace->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createShift(string $date, string $start, string $end): Shift
    {
        return $this->createShiftAt($this->workplace, $date, $start, $end);
    }

    private function registerApproveShiftUser(Shift $shift, User $employee): ShiftUser
    {
        $this->actingAs($employee, 'api')
            ->postJson("/user/shifts/{$shift->id}/register")
            ->assertOk();

        $shiftUser = ShiftUser::query()->where('shift_id', $shift->id)->where('user_id', $employee->id)->firstOrFail();

        $this->actingAs($this->shiftAdmin, 'api')
            ->putJson("/manage/shifts/{$shift->id}", [
                'fixed_total' => 0,
                'temp_total' => 5,
                'fixed' => ['pending_ids' => [], 'rejected_ids' => [], 'approved_ids' => []],
                'temp' => [
                    'pending_ids' => [],
                    'rejected_ids' => [],
                    'approved_ids' => [$this->workplacePosition->id => [$employee->id]],
                ],
            ])
            ->assertOk();

        return $shiftUser->fresh();
    }

    public function testUnconfirmedEscalationLifecycle(): void
    {
        Carbon::setTestNow('2026-08-08 08:00:00');
        $employee = $this->createEmployeeAt($this->workplace);
        // Shift two days out; start/end far from "now" so only the final-confirmation branch
        // is exercised by the first job run below.
        $shift = $this->createShift('2026-08-10', '14:00:00', '18:00:00');
        $shiftUser = $this->registerApproveShiftUser($shift, $employee);

        // 3 hours before start: inside the final confirmation window.
        Carbon::setTestNow('2026-08-10 11:00:00');
        (new ShiftReminder())->handle();
        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::CONFIRM_REMINDER->value,
            'user_id' => $employee->id,
        ]);
        $this->assertNotNull($shiftUser->fresh()->final_confirmation_reminded_at);

        // 2 hours before start: inside the admin unconfirmed-alert window.
        Carbon::setTestNow('2026-08-10 12:00:00');
        (new ShiftAdminAlert())->handle();
        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::SHIFT_UNCONFIRMED_ALERT->value,
            'user_id' => $this->shiftAdmin->id,
        ]);
        $this->assertNotNull($shift->fresh()->unconfirmed_alerted_at);

        // Employee confirms through the real endpoint.
        $this->actingAs($employee, 'api')
            ->postJson("/user/my-schedules/{$shiftUser->id}/confirmed")
            ->assertOk();
        $this->assertNotNull($shiftUser->fresh()->confirmed_at);

        // Advance past shift start and re-run both jobs: no further notification of either kind.
        Carbon::setTestNow('2026-08-10 15:00:00');
        Notification::query()->delete();
        (new ShiftReminder())->handle();
        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::CONFIRM_REMINDER->value,
            'user_id' => $employee->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::SHIFT_UNCONFIRMED_ALERT->value,
            'user_id' => $this->shiftAdmin->id,
        ]);
    }

    public function testMissingCheckInEscalationLifecycle(): void
    {
        Carbon::setTestNow('2026-08-08 08:00:00');
        $employee = $this->createEmployeeAt($this->workplace);
        $shift = $this->createShift('2026-08-10', '09:00:00', '13:00:00');
        $shiftUser = $this->registerApproveShiftUser($shift, $employee);

        $this->actingAs($employee, 'api')
            ->postJson("/user/my-schedules/{$shiftUser->id}/confirmed")
            ->assertOk();

        // 15 minutes after start, past the default 10-minute delay, without a check-in.
        Carbon::setTestNow('2026-08-10 09:15:00');
        (new ShiftAdminAlert())->handle();
        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::SHIFT_MISSING_CHECK_IN_ALERT->value,
            'user_id' => $this->shiftAdmin->id,
        ]);
        $this->assertNotNull($shift->fresh()->missing_check_in_alerted_at);

        // Check in through the real admin endpoint, then run again: nothing new.
        $this->actingAs($this->shiftAdmin, 'api')
            ->postJson("/manage/shifts/shift-users/{$shiftUser->id}/check-in", [
                'date' => '2026-08-10',
                'time' => '09:05',
            ])
            ->assertOk();

        Notification::query()->delete();
        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::SHIFT_MISSING_CHECK_IN_ALERT->value,
        ]);
    }

    public function testAccumulatedHistoryIsNeitherReprocessedNorCounted(): void
    {
        // The prior shift shares the new shift's date (so it still falls inside the job's
        // scanned window and genuinely exercises the whereNull(...)/orWhereNull(...) guard,
        // rather than being excluded by the date window alone) but runs earlier in the day.
        // Its approval must go through while it is still in the future relative to "now"
        // (AssignShiftUsersAction rejects editing a shift whose end has already passed), so
        // it is registered/approved before time advances past it.
        Carbon::setTestNow('2026-08-08 08:00:00');
        $priorEmployee = $this->createEmployeeAt($this->workplace);
        $priorShift = $this->createShift('2026-08-10', '05:00:00', '07:00:00');
        $priorShiftUser = $this->registerApproveShiftUser($priorShift, $priorEmployee);
        $staleUnconfirmedAt = Carbon::parse('2026-08-10 04:00:00');
        $staleMissingCheckInAt = Carbon::parse('2026-08-10 07:10:00');
        $priorShift->update([
            'unconfirmed_alerted_at' => $staleUnconfirmedAt,
            'missing_check_in_alerted_at' => $staleMissingCheckInAt,
        ]);
        $this->actingAs($this->shiftAdmin, 'api')
            ->postJson("/manage/shifts/shift-users/{$priorShiftUser->id}/check-in", [
                'date' => '2026-08-10',
                'time' => '05:05',
            ])
            ->assertOk();

        $employee = $this->createEmployeeAt($this->workplace, gender: 'male');
        $shift = $this->createShift('2026-08-10', '09:00:00', '13:00:00');
        $this->registerApproveShiftUser($shift, $employee);

        Carbon::setTestNow('2026-08-10 07:30:00');
        (new ShiftAdminAlert())->handle();

        $notification = Notification::query()
            ->where('type', NotificationType::SHIFT_UNCONFIRMED_ALERT->value)
            ->where('user_id', $this->shiftAdmin->id)
            ->firstOrFail();
        $this->assertEqualsCanonicalizing([$employee->id], $notification->data['user_ids']);

        // The old shift's stale timestamps are untouched — its already-resolved alerts were
        // not reprocessed by this run.
        $this->assertEquals($staleUnconfirmedAt->toDateTimeString(), $priorShift->fresh()->unconfirmed_alerted_at);
        $this->assertEquals(
            $staleMissingCheckInAt->toDateTimeString(),
            $priorShift->fresh()->missing_check_in_alerted_at
        );
    }

    public function testChatShortcutRoundTrip(): void
    {
        Carbon::setTestNow('2026-08-08 08:00:00');
        $admin = User::factory()->create();
        $permissions = [
            Permission::query()->firstOrCreate(
                ['name' => PermissionName::ChatView->value],
                ['display_name' => 'Chat View']
            )->id,
            Permission::query()->firstOrCreate(
                ['name' => PermissionName::ChatCreate->value],
                ['display_name' => 'Chat Create']
            )->id,
        ];
        $admin->permissions()->sync($permissions);
        $employee = $this->createEmployeeAt($this->workplace);

        $lookupBefore = $this->actingAs($admin, 'api')->getJson(
            "/admin/chat/direct-conversation?user_id={$employee->id}"
        );
        $lookupBefore->assertOk();
        $this->assertNull($lookupBefore->json('data.conversation_id'));

        $created = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$employee->id],
        ]);
        $created->assertOk();
        $conversationId = $created->json('data.id');
        $this->assertNotNull($conversationId);

        $lookupAfter = $this->actingAs($admin, 'api')->getJson(
            "/admin/chat/direct-conversation?user_id={$employee->id}"
        );
        $lookupAfter->assertOk();
        $this->assertSame($conversationId, $lookupAfter->json('data.conversation_id'));

        // Activating the shortcut again does not create a second conversation. The internal admin
        // group is an admin_group too, so it is excluded by its system key.
        $this->assertSame(
            1,
            ChatConversation::query()
                ->where('type', ChatConversationType::AdminGroup->value)
                ->whereNull('system_key')
                ->count()
        );
    }
}
