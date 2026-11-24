<?php

namespace Tests\Feature\Manage\Shift;

use App\Actions\Chat\Lifecycle\AutoCloseConversationAction;
use App\Actions\Manage\Shift\DeleteShiftAction;
use App\Actions\Manage\Shift\GetShiftDeleteImpactAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\ErrorCode;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Enums\ShiftPlanDraftAction;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Events\ChatConversationStateChanged;
use App\Events\NewChatMessage;
use App\Jobs\AutoCloseShiftGroupChats;
use App\Models\ChatConversation;
use App\Models\CheckIn;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftReport;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Services\Manage\ShiftPlanningLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DeleteShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');

        $this->admin = User::factory()->create();
        $this->viewer = User::factory()->create();

        // The grant migration already inserts shift.delete, so create-or-reuse it here.
        $deletePermission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::ShiftDelete->value],
            ['display_name' => 'Shift Delete']
        );
        $listPermission = Permission::create([
            'name' => PermissionName::ShiftList->value,
            'display_name' => 'Shift List',
        ]);
        $createPermission = Permission::create([
            'name' => PermissionName::ShiftCreate->value,
            'display_name' => 'Shift Create',
        ]);
        // ShiftEdit drives the Shift Planner endpoints and ChatCreate the shift-chat endpoint,
        // both used by the lifecycle tests to reach state through real flows.
        $editPermission = Permission::create([
            'name' => PermissionName::ShiftEdit->value,
            'display_name' => 'Shift Edit',
        ]);
        $chatCreatePermission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::ChatCreate->value],
            ['display_name' => 'Chat Create']
        );
        $this->admin->givePermission($deletePermission, null);
        $this->admin->givePermission($listPermission, null);
        $this->admin->givePermission($createPermission, null);
        $this->admin->givePermission($editPermission, null);
        $this->admin->givePermission($chatCreatePermission, null);
        $this->viewer->givePermission($listPermission, null);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->workplace)) {
            $lockService = app(ShiftPlanningLockService::class);
            $lock = $lockService->get($this->workplace->id);
            if ($lock) {
                $lockService->release($this->workplace->id, $lock['user_id']);
            }
        }
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── permissions ──────────────────────────────────────────────────────────

    public function testDeleteImpactRequiresShiftDeletePermission(): void
    {
        $shift = $this->createShift('2026-05-10');

        $this->actingAs($this->viewer, 'api')
            ->getJson("/manage/shifts/{$shift->id}/delete-impact")
            ->assertForbidden();
    }

    public function testDeleteRequiresShiftDeletePermission(): void
    {
        $shift = $this->createShift('2026-05-10');
        $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Pending->value);

        $this->actingAs($this->viewer, 'api')
            ->deleteJson("/manage/shifts/{$shift->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseCount('shift_users', 1);
    }

    public function testDeleteIsRefusedForAWorkplaceOutsideTheAdminsScope(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $scopedAdmin = User::factory()->create();
        $scopedAdmin->givePermission(
            Permission::query()->where('name', PermissionName::ShiftDelete->value)->first(),
            $otherWorkplace
        );
        $shift = $this->createShift('2026-05-10');

        $this->actingAs($scopedAdmin, 'api')
            ->getJson("/manage/shifts/{$shift->id}/delete-impact")
            ->assertForbidden();
        $this->actingAs($scopedAdmin, 'api')
            ->deleteJson("/manage/shifts/{$shift->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
    }

    // ── impact preview ───────────────────────────────────────────────────────

    public function testImpactReportsEveryDependentRecordGroup(): void
    {
        $shift = $this->createShift('2026-05-10');
        $pendingTemp = $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Pending->value);
        $approvedFixed = $this->createShiftUser(
            $shift,
            User::factory()->create(),
            ShiftUserStatus::Approved->value,
            true
        );
        $approvedTemp = $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Approved->value);
        $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Rejected->value);

        $this->createCheckIn($approvedFixed, checkedOut: true);
        $this->createCheckIn($approvedTemp, checkedOut: false);
        ShiftReport::create([
            'shift_user_id' => $approvedFixed->id,
            'user_id' => $approvedFixed->user_id,
            'date' => $shift->date,
        ]);
        ShiftAbsentNotice::create(['shift_user_id' => $pendingTemp->id]);
        $this->createShiftChat($shift);
        $this->createRecruitmentNotificationFor($shift);
        $this->createRecruitmentNotificationFor($shift);
        // Another shift's data must not leak into this preview.
        $otherShift = $this->createShift('2026-05-11');
        $otherShiftUser = $this->createShiftUser(
            $otherShift,
            User::factory()->create(),
            ShiftUserStatus::Approved->value
        );
        $this->createCheckIn($otherShiftUser, checkedOut: true);
        $this->createRecruitmentNotificationFor($otherShift);
        $this->createShiftChat($otherShift);
        // Planner drafts: one moving this shift's approved row elsewhere (staged on the other
        // shift's cell), one staged on this shift's own cell, one unrelated on the other shift.
        $this->createPlanDraft($otherShift, $approvedTemp);
        $this->createPlanDraft(
            $shift,
            $this->createShiftUser($otherShift, User::factory()->create(), ShiftUserStatus::Approved->value)
        );
        $this->createPlanDraft($otherShift, $otherShiftUser);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}/delete-impact");

        $response->assertOk();
        $response->assertJsonPath('data.shift_users.total', 4);
        $response->assertJsonPath('data.shift_users.by_status.pending', 1);
        $response->assertJsonPath('data.shift_users.by_status.approved', 2);
        $response->assertJsonPath('data.shift_users.by_status.rejected', 1);
        $response->assertJsonPath('data.shift_users.fixed', 1);
        $response->assertJsonPath('data.shift_users.temp', 3);
        $response->assertJsonPath('data.check_ins.total', 2);
        $response->assertJsonPath('data.check_ins.checked_out', 1);
        $response->assertJsonPath('data.shift_reports', 1);
        $response->assertJsonPath('data.shift_absent_notices', 1);
        $response->assertJsonPath('data.shift_plan_drafts', 2);
        $response->assertJsonPath('data.chat_conversations', 1);
        $response->assertJsonPath('data.recruitment_notifications', 2);
        $response->assertJsonPath('data.blocked', true);
        $response->assertJsonPath('data.block_reason', GetShiftDeleteImpactAction::BLOCK_REASON_HAS_CHECK_INS);

        $users = $response->json('data.shift_users.users');
        $this->assertCount(4, $users);
        $this->assertSame($approvedFixed->user_id, $users[1]['id']);
        $this->assertSame(ShiftUserStatus::Approved->value, $users[1]['status']);
        $this->assertTrue($users[1]['is_fixed']);
        $this->assertNotEmpty($users[1]['name']);
    }

    public function testImpactDoesNotCountAnAlreadyClosedShiftChat(): void
    {
        $shift = $this->createShift('2026-05-10');
        $this->createShiftChat($shift, ChatConversationStatus::Closed->value);

        $this->actingAs($this->admin, 'api')
            ->getJson("/manage/shifts/{$shift->id}/delete-impact")
            ->assertOk()
            ->assertJsonPath('data.chat_conversations', 0);
    }

    public function testImpactCapsTheUserListButReportsTheFullTotal(): void
    {
        $shift = $this->createShift('2026-05-10');
        foreach (
            User::factory()
                ->count(GetShiftDeleteImpactAction::USER_LIST_LIMIT + 3)
                ->create()
            as $user
        ) {
            $this->createShiftUser($shift, $user, ShiftUserStatus::Pending->value);
        }

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}/delete-impact");

        $response->assertOk();
        $response->assertJsonPath('data.shift_users.total', GetShiftDeleteImpactAction::USER_LIST_LIMIT + 3);
        $this->assertCount(GetShiftDeleteImpactAction::USER_LIST_LIMIT, $response->json('data.shift_users.users'));
        $response->assertJsonPath('data.blocked', false);
        $response->assertJsonPath('data.block_reason', null);
    }

    public function testImpactFlagsAnEndedShiftWithApprovedStaffAsBlocked(): void
    {
        $shift = $this->createShift('2026-05-01');
        $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Approved->value);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}/delete-impact");

        $response->assertOk();
        $response->assertJsonPath('data.blocked', true);
        $response->assertJsonPath('data.block_reason', GetShiftDeleteImpactAction::BLOCK_REASON_ENDED_WITH_APPROVED);
    }

    public function testImpactDoesNotBlockAnEndedShiftThatOnlyHadPendingOrRejectedRows(): void
    {
        $shift = $this->createShift('2026-05-01');
        $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Pending->value);
        $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Rejected->value);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}/delete-impact");

        $response->assertOk();
        $response->assertJsonPath('data.blocked', false);
    }

    // ── guard ────────────────────────────────────────────────────────────────

    public function testDeleteIsBlockedWhenAnyCheckInExistsAndNothingIsRemoved(): void
    {
        $shift = $this->createShift('2026-05-10');
        $approved = $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Approved->value);
        $pending = $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Pending->value);
        $checkIn = $this->createCheckIn($approved, checkedOut: false);
        $report = ShiftReport::create([
            'shift_user_id' => $approved->id,
            'user_id' => $approved->user_id,
            'date' => $shift->date,
        ]);
        $notice = ShiftAbsentNotice::create(['shift_user_id' => $pending->id]);
        $conversation = ChatConversation::create([
            'type' => ChatConversationType::ShiftGroup->value,
            'title' => 'Shift chat',
            'shift_id' => $shift->id,
        ]);
        $draft = $this->createPlanDraft($shift, $pending);

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/manage/shifts/{$shift->id}");

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::ShiftCannotDeleteHasAttendance->value);
        $response->assertJsonPath('data.block_reason', GetShiftDeleteImpactAction::BLOCK_REASON_HAS_CHECK_INS);

        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('shift_users', ['id' => $approved->id]);
        $this->assertDatabaseHas('shift_users', ['id' => $pending->id]);
        $this->assertDatabaseHas('check_ins', ['id' => $checkIn->id]);
        $this->assertDatabaseHas('shift_reports', ['id' => $report->id]);
        $this->assertDatabaseHas('shift_absent_notices', ['id' => $notice->id]);
        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id, 'shift_id' => $shift->id]);
        $this->assertDatabaseHas('shift_plan_drafts', ['id' => $draft->id, 'shift_user_id' => $pending->id]);
    }

    public function testDeleteIsBlockedForAnEndedShiftWithApprovedStaff(): void
    {
        $shift = $this->createShift('2026-05-01');
        $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Approved->value);

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/manage/shifts/{$shift->id}");

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::ShiftCannotDeleteHasAttendance->value);
        $response->assertJsonPath('data.block_reason', GetShiftDeleteImpactAction::BLOCK_REASON_ENDED_WITH_APPROVED);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseCount('shift_users', 1);
    }

    // ── happy path ───────────────────────────────────────────────────────────

    public function testDeleteRemovesTheShiftAndItsDependentsAndClosesOrDiscardsTheRest(): void
    {
        $shift = $this->createShift('2026-05-10');
        $pending = $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Pending->value);
        $approved = $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Approved->value);
        $notice = ShiftAbsentNotice::create(['shift_user_id' => $approved->id]);
        $report = ShiftReport::create([
            'shift_user_id' => $approved->id,
            'user_id' => $approved->user_id,
            'date' => $shift->date,
        ]);
        $conversation = $this->createShiftChat($shift);
        $notification = $this->createRecruitmentNotificationFor($shift);
        // A sibling shift must be untouched.
        $otherShift = $this->createShift('2026-05-11');
        $otherShiftUser = $this->createShiftUser(
            $otherShift,
            User::factory()->create(),
            ShiftUserStatus::Pending->value
        );
        $movingDraft = $this->createPlanDraft($otherShift, $approved);
        $cellDraft = $this->createPlanDraft($shift, $otherShiftUser);
        $otherDraft = $this->createPlanDraft($otherShift, $otherShiftUser);
        $otherConversation = $this->createShiftChat($otherShift);

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/manage/shifts/{$shift->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $shift->id);

        $this->assertDatabaseMissing('shifts', ['id' => $shift->id]);
        $this->assertDatabaseMissing('shift_users', ['id' => $pending->id]);
        $this->assertDatabaseMissing('shift_users', ['id' => $approved->id]);
        $this->assertDatabaseMissing('shift_absent_notices', ['id' => $notice->id]);
        $this->assertDatabaseMissing('shift_reports', ['id' => $report->id]);
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'shift_id' => null,
            'status' => ChatConversationStatus::Closed->value,
            'close_reason' => DeleteShiftAction::CHAT_CLOSE_REASON,
        ]);
        $this->assertDatabaseMissing('shift_plan_drafts', ['id' => $movingDraft->id]);
        $this->assertDatabaseMissing('shift_plan_drafts', ['id' => $cellDraft->id]);
        // Sent recruitment notifications have no FK and keep their (now dangling) shift_id.
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
        $this->assertDatabaseHas('shifts', ['id' => $otherShift->id]);
        $this->assertDatabaseHas('shift_users', ['id' => $otherShiftUser->id]);
        $this->assertDatabaseHas('shift_plan_drafts', [
            'id' => $otherDraft->id,
            'shift_user_id' => $otherShiftUser->id,
        ]);
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $otherConversation->id,
            'shift_id' => $otherShift->id,
            'status' => ChatConversationStatus::Open->value,
        ]);
    }

    public function testDeleteClosesTheOpenShiftChatThroughTheChatLifecycleAndKeepsItsMembers(): void
    {
        $shift = $this->createShift('2026-05-10');
        $employee = User::factory()->create();
        $this->createShiftUser($shift, $employee, ShiftUserStatus::Approved->value);

        // The shift chat is created through the real endpoint, which enrols the approved staff.
        $created = $this->actingAs($this->admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $shift->id,
        ]);
        $created->assertOk();
        $conversationId = $created->json('data.id');
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $employee->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        Event::fake([ChatConversationStateChanged::class, NewChatMessage::class]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/manage/shifts/{$shift->id}")
            ->assertOk();

        $conversation = ChatConversation::query()->findOrFail($conversationId);
        $this->assertSame(ChatConversationStatus::Closed->value, $conversation->status);
        $this->assertSame(DeleteShiftAction::CHAT_CLOSE_REASON, $conversation->close_reason);
        $this->assertNotNull($conversation->closed_at);
        $this->assertNull($conversation->closed_by_user_id);
        $this->assertNull($conversation->shift_id);
        $this->assertDatabaseHas('chat_audit_logs', [
            'conversation_id' => $conversationId,
            'action' => ChatAuditAction::AutoClosed->value,
            'actor_user_id' => null,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversationId,
            'message_type' => ChatMessageType::System->value,
            'body' => ChatSystemMessageType::ConversationClosed->value,
        ]);
        // Same end state as the scheduled auto-close: the chat is read-only but its history and
        // members stay so the staff can still read what was agreed.
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $employee->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        Event::assertDispatched(
            ChatConversationStateChanged::class,
            fn(ChatConversationStateChanged $event) => $event->conversation->id === $conversationId
        );
        Event::assertDispatched(NewChatMessage::class);

        // The scheduled auto-close has nothing left to do for this chat and must not disturb it.
        (new AutoCloseShiftGroupChats())->handle(app(AutoCloseConversationAction::class));
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversationId,
            'close_reason' => DeleteShiftAction::CHAT_CLOSE_REASON,
        ]);
    }

    public function testDeletedShiftsChatCannotBeReopenedByAdminOrDeputy(): void
    {
        $shift = $this->createShift('2026-05-10');
        $employee = User::factory()->create();
        $this->createShiftUser($shift, $employee, ShiftUserStatus::Approved->value);
        $this->grantChatLifecyclePermissions($this->admin);
        $this->grantChatLifecyclePermissions($employee, edit: false);

        // Real flow: the admin creates the shift chat (joining it as admin) and promotes the
        // approved employee to deputy, the two roles ReopenConversationAction always lets through.
        $conversationId = $this->actingAs($this->admin, 'api')
            ->postJson('/admin/chat/conversations', [
                'type' => ChatConversationType::ShiftGroup->value,
                'shift_id' => $shift->id,
            ])
            ->assertOk()
            ->json('data.id');
        $this->actingAs($this->admin, 'api')
            ->patchJson("/admin/chat/conversations/{$conversationId}/participants/{$employee->id}/role", [
                'role' => ChatParticipantRole::Deputy->value,
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/manage/shifts/{$shift->id}")
            ->assertOk();

        Event::fake([ChatConversationStateChanged::class, NewChatMessage::class]);

        foreach ([$this->admin, $employee] as $actor) {
            $this->actingAs($actor, 'api')
                ->postJson("/admin/chat/conversations/{$conversationId}/reopen")
                ->assertStatus(422)
                ->assertJsonPath('error', ErrorCode::ChatShiftDeleted->value);
        }

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversationId,
            'status' => ChatConversationStatus::Closed->value,
            'close_reason' => DeleteShiftAction::CHAT_CLOSE_REASON,
            'shift_id' => null,
        ]);
        $this->assertDatabaseMissing('chat_audit_logs', [
            'conversation_id' => $conversationId,
            'action' => ChatAuditAction::ConversationReopened->value,
        ]);
        $this->assertDatabaseMissing('chat_messages', [
            'conversation_id' => $conversationId,
            'body' => ChatSystemMessageType::ConversationReopened->value,
        ]);
        Event::assertNotDispatched(ChatConversationStateChanged::class);

        // The detail tells the client the chat is orphaned so it can drop its Reopen control.
        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/chat/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.type', ChatConversationType::ShiftGroup->value)
            ->assertJsonPath('data.status', ChatConversationStatus::Closed->value)
            ->assertJsonPath('data.shift_id', null);
    }

    public function testDeletedShiftIsGoneFromDetailAndList(): void
    {
        $shift = $this->createShift('2026-05-10');

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/manage/shifts/{$shift->id}")
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->getJson("/manage/shifts/{$shift->id}")
            ->assertNotFound();
    }

    // ── lifecycle ────────────────────────────────────────────────────────────

    public function testShiftCanBeRecreatedForTheSameSlotAfterDelete(): void
    {
        $payload = [
            'date' => '2026-05-10',
            'workplace_shift_id' => $this->workplaceShift->id,
            'fixed_total' => 1,
            'temp_total' => 2,
            'max_registered_temp' => null,
            'break_minutes' => 60,
        ];

        $createResponse = $this->actingAs($this->admin, 'api')->postJson('/manage/shifts', $payload);
        $createResponse->assertOk();
        $shiftId = $createResponse->json('data.id');

        // An employee registers through the real endpoint, leaving a pending row behind.
        $employee = $this->createRegisterableUser();
        $this->actingAs($employee, 'api')
            ->postJson("/user/shifts/{$shiftId}/register")
            ->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $shiftId,
            'user_id' => $employee->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        // Re-creating the same slot is refused while the shift still exists.
        $this->actingAs($this->admin, 'api')
            ->postJson('/manage/shifts', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error', ErrorCode::ShiftAlreadyExists->value);

        $impact = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shiftId}/delete-impact");
        $impact->assertOk();
        $impact->assertJsonPath('data.blocked', false);
        $impact->assertJsonPath('data.shift_users.by_status.pending', 1);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/manage/shifts/{$shiftId}")
            ->assertOk();
        $this->assertDatabaseMissing('shift_users', ['shift_id' => $shiftId]);

        // The unique (workplace, date, workplace_shift) index no longer stands in the way.
        $recreate = $this->actingAs($this->admin, 'api')->postJson('/manage/shifts', $payload);
        $recreate->assertOk();
        $this->assertNotSame($shiftId, $recreate->json('data.id'));
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseHas('shifts', [
            'id' => $recreate->json('data.id'),
            'workplace_shift_id' => $this->workplaceShift->id,
            'date' => '2026-05-10',
        ]);
    }

    public function testDeleteLeavesAnAlreadyClosedShiftChatsCloseRecordUntouched(): void
    {
        $shift = $this->createShift('2026-05-10');
        $alreadyClosed = $this->createShiftChat($shift, ChatConversationStatus::Closed->value);
        $alreadyClosed->forceFill(['close_reason' => 'manual', 'closed_at' => '2026-05-01 00:00:00'])->save();

        Event::fake([ChatConversationStateChanged::class, NewChatMessage::class]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/manage/shifts/{$shift->id}")
            ->assertOk();

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $alreadyClosed->id,
            'shift_id' => null,
            'status' => ChatConversationStatus::Closed->value,
            'close_reason' => 'manual',
            'closed_at' => '2026-05-01 00:00:00',
        ]);
        $this->assertDatabaseMissing('chat_audit_logs', [
            'conversation_id' => $alreadyClosed->id,
            'action' => ChatAuditAction::AutoClosed->value,
        ]);
        Event::assertNotDispatched(ChatConversationStateChanged::class);
    }

    public function testDeleteDiscardsPlannerDraftsSoFinalizeCannotSilentlyDropAMove(): void
    {
        // Finalize only accepts dates from today + 2 (2026-05-10 with the frozen clock).
        $date = '2026-05-12';
        $eveningType = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '19:00:00',
            'end_time' => '22:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $sourceShift = $this->createShift($date);
        $targetShift = $this->createShift($date, ['workplace_shift_id' => $eveningType->id]);
        $mover = $this->createRegisterableUser();
        $newcomer = $this->createRegisterableUser();
        $live = $this->createShiftUser($sourceShift, $mover, ShiftUserStatus::Approved->value);

        app(ShiftPlanningLockService::class)->acquire($this->workplace->id, $this->admin->id, $this->admin->name);

        // Through the planner: move the approved employee from the source shift to the target
        // shift, and plan a newcomer onto the source shift itself.
        $this->actingAs($this->admin, 'api')
            ->putJson('/manage/shift-planning/draft', [
                'workplace_id' => $this->workplace->id,
                'from' => $date,
                'to' => $date,
                'drafts' => [
                    [
                        'date' => $date,
                        'workplace_shift_id' => $eveningType->id,
                        'user_id' => $mover->id,
                        'shift_user_id' => $live->id,
                        'workplace_position_id' => $this->workplacePosition->id,
                        'is_fixed' => false,
                        'action' => ShiftPlanDraftAction::Update->value,
                    ],
                    [
                        'date' => $date,
                        'workplace_shift_id' => $this->workplaceShift->id,
                        'user_id' => $newcomer->id,
                        'shift_user_id' => null,
                        'workplace_position_id' => $this->workplacePosition->id,
                        'is_fixed' => false,
                        'action' => ShiftPlanDraftAction::Add->value,
                    ],
                ],
            ])
            ->assertOk();
        $this->assertDatabaseHas('shift_plan_drafts', [
            'user_id' => $mover->id,
            'workplace_shift_id' => $eveningType->id,
            'shift_user_id' => $live->id,
            'action' => ShiftPlanDraftAction::Update->value,
        ]);
        $this->assertDatabaseHas('shift_plan_drafts', [
            'user_id' => $newcomer->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'action' => ShiftPlanDraftAction::Add->value,
        ]);

        $impact = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$sourceShift->id}/delete-impact");
        $impact->assertOk();
        $impact->assertJsonPath('data.shift_plan_drafts', 2);
        // The move is staged on the target shift's cell, so deleting the target would orphan it
        // as well; the newcomer's add is only staged on the source shift.
        $this->actingAs($this->admin, 'api')
            ->getJson("/manage/shifts/{$targetShift->id}/delete-impact")
            ->assertJsonPath('data.shift_plan_drafts', 1);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/manage/shifts/{$sourceShift->id}")
            ->assertOk();

        $this->assertDatabaseCount('shift_plan_drafts', 0);
        $this->assertDatabaseHas('shifts', ['id' => $targetShift->id]);

        // Finalizing the planner afterwards has nothing to commit: the employee is neither
        // moved onto the target shift nor reported as such.
        $finalize = $this->actingAs($this->admin, 'api')->postJson('/manage/shift-planning/finalize', [
            'workplace_id' => $this->workplace->id,
            'cells' => [['user_id' => $mover->id, 'date' => $date], ['user_id' => $newcomer->id, 'date' => $date]],
        ]);
        $finalize->assertOk();
        $this->assertSame(0, $finalize->json('data.results.0.notified_count'));
        $this->assertSame([], $finalize->json('data.results.0.errors'));
        $this->assertDatabaseCount('shift_users', 0);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createShift(string $date, array $attributes = []): Shift
    {
        return Shift::create(
            array_merge(
                [
                    'workplace_id' => $this->workplace->id,
                    'workplace_shift_id' => $this->workplaceShift->id,
                    'creator_id' => $this->admin->id,
                    'date' => $date,
                    'adjusted_date' => $date,
                    'temp_total' => 3,
                    'fixed_total' => 1,
                ],
                $attributes
            )
        );
    }

    private function createShiftUser(Shift $shift, User $user, string $status, bool $isFixed = false): ShiftUser
    {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => $status,
            'is_fixed' => $isFixed,
        ]);
    }

    private function createCheckIn(ShiftUser $shiftUser, bool $checkedOut): CheckIn
    {
        return CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shiftUser->shift_id,
            'user_id' => $shiftUser->user_id,
            'check_in_time' => "{$shiftUser->shift->date} 09:00:00",
            'check_out_time' => $checkedOut ? "{$shiftUser->shift->date} 18:00:00" : null,
        ]);
    }

    /**
     * An `update` draft staged on $shift's cell that replaces $shiftUser's live row on finalize
     * (a move when the live row belongs to another shift).
     */
    private function createPlanDraft(Shift $shift, ShiftUser $shiftUser): ShiftPlanDraft
    {
        return ShiftPlanDraft::create([
            'workplace_id' => $shift->workplace_id,
            'date' => $shift->date,
            'workplace_shift_id' => $shift->workplace_shift_id,
            'user_id' => $shiftUser->user_id,
            'shift_user_id' => $shiftUser->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'is_fixed' => false,
            'action' => ShiftPlanDraftAction::Update->value,
        ]);
    }

    private function grantChatLifecyclePermissions(User $user, bool $edit = true): void
    {
        $names = [PermissionName::ChatView->value];
        if ($edit) {
            $names[] = PermissionName::ChatEdit->value;
        }
        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name]);
            $user->givePermission($permission, null);
        }
    }

    private function createShiftChat(
        Shift $shift,
        string $status = ChatConversationStatus::Open->value
    ): ChatConversation {
        return ChatConversation::create([
            'type' => ChatConversationType::ShiftGroup->value,
            'status' => $status,
            'title' => 'Shift chat',
            'shift_id' => $shift->id,
            'closed_at' => $status === ChatConversationStatus::Closed->value ? now() : null,
        ]);
    }

    private function createRecruitmentNotificationFor(Shift $shift): Notification
    {
        return Notification::create([
            'type' => NotificationType::RECRUITMENT->value,
            'title' => 'Urgent staff needed',
            'content' => 'Please help cover this shift',
            'status' => NotificationStatus::SENT,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'user_id' => null,
            'data' => [
                'shift_id' => $shift->id,
                'workplace_id' => $shift->workplace_id,
            ],
        ]);
    }

    private function createRegisterableUser(): User
    {
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'STAFF-' . fake()->unique()->numberBetween(1000, 9999),
        ]);

        $user->info()->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        WorkplaceUser::create(['user_id' => $user->id, 'workplace_id' => $this->workplace->id]);

        return $user->load('info');
    }
}
