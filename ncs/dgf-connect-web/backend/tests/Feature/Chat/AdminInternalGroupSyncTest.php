<?php

namespace Tests\Feature\Chat;

use App\Actions\Chat\AdminGroup\SyncAdminInternalParticipantsAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemConversationKey;
use App\Enums\ChatSystemMessageType;
use App\Enums\PermissionName;
use App\Jobs\SyncAdminInternalChatParticipants;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AdminInternalGroupSyncTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testMigrationCreatesTheInternalGroupAsAnOpenAdminGroup(): void
    {
        $conversation = $this->internalGroup();

        $this->assertSame(ChatConversationType::AdminGroup->value, $conversation->type);
        $this->assertSame(ChatConversationStatus::Open->value, $conversation->status);
        $this->assertNotNull($conversation->title);
        $this->assertTrue($conversation->isSystem());
    }

    public function testAssigningAnAdminRoleAddsTheUserThroughTheRealEndpoint(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::UserAssignRole]);
        $this->adminRole();
        $target = User::factory()->create();

        $response = $this->actingAs($actor, 'api')->postJson("/admin/users/{$target->id}/roles", [
            'role_name' => 'manager',
        ]);

        $response->assertStatus(200);

        $participant = $this->participantOf($target);
        $this->assertNotNull($participant);
        $this->assertSame(ChatParticipantStatus::Active->value, $participant->status);
        $this->assertSame(ChatParticipantRole::Admin->value, $participant->role);

        $this->assertTrue(
            ChatMessage::query()
                ->where('conversation_id', $this->internalGroup()->id)
                ->where('body', ChatSystemMessageType::ParticipantAdded->value)
                ->whereJsonContains('system_payload->user_id', $target->id)
                ->exists()
        );
    }

    public function testRemovingTheAdminRoleEndsMembershipButKeepsTheHistory(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::UserAssignRole]);
        $this->adminRole();
        $admin = User::factory()->create();
        // Held directly, so losing the role leaves the chat permission in place and the refusal
        // below can only come from membership, not from the permission middleware.
        $admin
            ->permissions()
            ->syncWithoutDetaching([
                Permission::query()->firstOrCreate(
                    ['name' => PermissionName::ChatView->value],
                    ['display_name' => 'Chat view']
                )->id,
            ]);

        $this->actingAs($actor, 'api')
            ->postJson("/admin/users/{$admin->id}/roles", ['role_name' => 'manager'])
            ->assertStatus(200);

        $conversation = $this->internalGroup();
        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$conversation->id}/messages", [
                'body' => 'Good morning',
                'client_message_id' => (string) Str::uuid(),
            ])
            ->assertStatus(200);

        $this->actingAs($actor, 'api')
            ->deleteJson("/admin/users/{$admin->id}/roles", ['role_name' => 'manager'])
            ->assertStatus(200);

        $participant = $this->participantOf($admin);
        $this->assertSame(ChatParticipantStatus::Removed->value, $participant->status);
        $this->assertNotNull($participant->removed_at);

        // The conversation and everything the account wrote in it survive losing access.
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'sender_user_id' => $admin->id,
            'body' => 'Good morning',
        ]);
        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);

        // A removed member no longer reaches the chat.
        $this->actingAs($admin, 'api')
            ->getJson("/admin/chat/conversations/{$conversation->id}")
            ->assertStatus(404);
    }

    public function testDisablingAnAdminRemovesThemAndEnablingRestoresMembershipWithTheirReadMarker(): void
    {
        $actor = $this->createUserWithPermissions([
            PermissionName::UserAssignRole,
            PermissionName::UserDisable,
            PermissionName::UserEnable,
        ]);
        $this->adminRole();
        $admin = User::factory()->create();

        $this->actingAs($actor, 'api')
            ->postJson("/admin/users/{$admin->id}/roles", ['role_name' => 'manager'])
            ->assertStatus(200);

        $conversation = $this->internalGroup();
        $message = ChatMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $actor->id,
        ]);
        $this->participantOf($admin)
            ->forceFill(['last_read_message_id' => $message->id])
            ->save();

        $this->actingAs($actor, 'api')
            ->postJson("/admin/users/{$admin->id}/disable", ['reason' => 'Left the company'])
            ->assertStatus(200);

        $this->assertSame(ChatParticipantStatus::Removed->value, $this->participantOf($admin)->status);

        $this->actingAs($actor, 'api')
            ->postJson("/admin/users/{$admin->id}/enable", ['reason' => 'test'])
            ->assertStatus(200);

        $participant = $this->participantOf($admin);
        $this->assertSame(ChatParticipantStatus::Active->value, $participant->status);
        $this->assertNull($participant->removed_at);
        // Rejoining must not re-mark history the account had already read.
        $this->assertSame($message->id, $participant->last_read_message_id);
    }

    public function testMovingManagerAuthOntoAndOffARoleSyncsEveryUserHoldingIt(): void
    {
        $role = Role::query()->create(['name' => 'coordinator', 'display_name' => 'Coordinator']);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $first->addRole($role);
        $second->addRole($role);

        $this->assertNull($this->participantOf($first));

        $role->givePermission($this->managerAuthPermission());

        $this->assertSame(ChatParticipantStatus::Active->value, $this->participantOf($first)->status);
        $this->assertSame(ChatParticipantStatus::Active->value, $this->participantOf($second)->status);

        $role->removePermission($this->managerAuthPermission());

        $this->assertSame(ChatParticipantStatus::Removed->value, $this->participantOf($first)->status);
        $this->assertSame(ChatParticipantStatus::Removed->value, $this->participantOf($second)->status);
    }

    public function testMembershipIgnoresPermissionsGrantedDirectlyToAUser(): void
    {
        // Mirrors ResolveWebAdminAccessAction: admin access is granted through roles only.
        $user = User::factory()->create();
        $user->permissions()->syncWithoutDetaching([$this->managerAuthPermission()->id]);

        app(SyncAdminInternalParticipantsAction::class)();

        $this->assertNull($this->participantOf($user));
    }

    public function testTheNightlyJobRepairsMembershipWrittenStraightToThePivot(): void
    {
        $role = $this->adminRole();
        $user = User::factory()->create();

        // A seeder or a data fix writing the pivot by hand fires no model event.
        DB::table('role_user')->insert([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'user_type' => User::class,
        ]);

        $this->assertNull($this->participantOf($user));

        (new SyncAdminInternalChatParticipants())->handle(app(SyncAdminInternalParticipantsAction::class));

        $this->assertSame(ChatParticipantStatus::Active->value, $this->participantOf($user)->status);
    }

    public function testSyncRecreatesTheGroupWhenItIsMissing(): void
    {
        ChatConversation::query()->where('system_key', ChatSystemConversationKey::AdminInternal->value)->delete();

        $this->adminRole();
        $admin = User::factory()->create();
        $admin->addRole(Role::query()->where('name', 'manager')->firstOrFail());

        $conversation = $this->internalGroup();
        $this->assertSame(ChatConversationType::AdminGroup->value, $conversation->type);
        $this->assertSame(ChatParticipantStatus::Active->value, $this->participantOf($admin)->status);
    }

    public function testRepeatedSyncsAreIdempotent(): void
    {
        $this->adminRole();
        $admin = User::factory()->create();
        $admin->addRole(Role::query()->where('name', 'manager')->firstOrFail());

        $conversation = $this->internalGroup();
        $messagesAfterJoin = ChatMessage::query()->where('conversation_id', $conversation->id)->count();

        app(SyncAdminInternalParticipantsAction::class)();
        app(SyncAdminInternalParticipantsAction::class)();

        $this->assertSame(
            $messagesAfterJoin,
            ChatMessage::query()->where('conversation_id', $conversation->id)->count()
        );
        $this->assertSame(
            1,
            ChatParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $admin->id)->count()
        );
    }

    public function testBatchCollapsesManyRoleChangesIntoASingleSync(): void
    {
        $this->adminRole();
        $role = Role::query()->where('name', 'manager')->firstOrFail();
        $users = User::factory()->count(3)->create();

        SyncAdminInternalParticipantsAction::batch(function () use ($users, $role) {
            foreach ($users as $user) {
                $user->addRole($role);
            }
        });

        $conversation = $this->internalGroup();
        foreach ($users as $user) {
            $this->assertSame(ChatParticipantStatus::Active->value, $this->participantOf($user)->status);
        }

        $this->assertSame(
            3,
            ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('body', ChatSystemMessageType::ParticipantAdded->value)
                ->count()
        );
    }

    public function testTheRosterIsReadUnderTheConversationRowLock(): void
    {
        $this->adminRole();
        User::factory()->create()->addRole('manager');

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        app(SyncAdminInternalParticipantsAction::class)();

        $lockIndex = $this->firstStatementMatching($statements, '/from `chat_conversations`.*for update/is');
        $rosterIndex = $this->firstStatementMatching($statements, '/from `chat_participants`/i', $lockIndex);

        $this->assertNotNull($lockIndex, 'the conversation row is never locked');
        $this->assertNotNull($rosterIndex, 'the roster is never read after the lock');
    }

    public function testAParticipantRowWrittenOutOfBandIsAdoptedInsteadOfDuplicated(): void
    {
        $this->adminRole();
        $admin = User::factory()->create();
        $admin->addRole('manager');

        $conversation = $this->internalGroup();
        $outsider = User::factory()->create();
        DB::table('chat_participants')->insert([
            'conversation_id' => $conversation->id,
            'user_id' => $outsider->id,
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
            'joined_at' => now(),
            'unread_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $outsider->addRole('manager');

        $this->assertSame(
            1,
            ChatParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $outsider->id)
                ->count()
        );
        $this->assertSame(ChatParticipantStatus::Active->value, $this->participantOf($outsider)->status);
        $this->assertSame(
            0,
            ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('body', ChatSystemMessageType::ParticipantAdded->value)
                ->whereJsonContains('system_payload->user_id', $outsider->id)
                ->count()
        );
    }

    /**
     * @param array<int, string> $statements
     */
    private function firstStatementMatching(array $statements, string $pattern, ?int $after = null): ?int
    {
        foreach ($statements as $index => $sql) {
            if ($after !== null && $index <= $after) {
                continue;
            }
            if (preg_match($pattern, $sql) === 1) {
                return $index;
            }
        }

        return null;
    }

    public function testRoleChangesInsideAnotherTransactionSyncOnlyAfterItCommits(): void
    {
        $this->adminRole();
        $spy = $this->spyOnSync();
        $user = User::factory()->create();
        $outside = DB::transactionLevel();

        DB::transaction(function () use ($user, $spy) {
            $user->addRole('manager');

            $this->assertSame(
                [],
                $spy->transactionLevels,
                'the sync ran inside the caller transaction and would hold the group row lock until it commits'
            );
        });

        $this->assertSame([$outside], $spy->transactionLevels);
    }

    public function testARoleChangeOutsideATransactionStillSyncsImmediately(): void
    {
        $this->adminRole();
        $spy = $this->spyOnSync();
        $outside = DB::transactionLevel();

        User::factory()->create()->addRole('manager');

        $this->assertSame([$outside], $spy->transactionLevels);
    }

    public function testARolledBackRoleChangeNeverSyncs(): void
    {
        $this->adminRole();
        $spy = $this->spyOnSync();
        $user = User::factory()->create();

        try {
            DB::transaction(function () use ($user) {
                $user->addRole('manager');

                throw new RuntimeException('caller failed after the role change');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $spy->transactionLevels);
    }

    private function spyOnSync(): SyncAdminInternalParticipantsAction
    {
        $spy = new class extends SyncAdminInternalParticipantsAction {
            /** @var array<int, int> */
            public array $transactionLevels = [];

            public function __construct() {}

            public function __invoke(): void
            {
                $this->transactionLevels[] = DB::transactionLevel();
            }
        };

        $this->app->instance(SyncAdminInternalParticipantsAction::class, $spy);

        return $spy;
    }

    private function internalGroup(): ChatConversation
    {
        return ChatConversation::query()
            ->where('system_key', ChatSystemConversationKey::AdminInternal->value)
            ->firstOrFail();
    }

    private function participantOf(User $user): ?ChatParticipant
    {
        return ChatParticipant::query()
            ->where('conversation_id', $this->internalGroup()->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function adminRole(): Role
    {
        $role = Role::query()->firstOrCreate(['name' => 'manager'], ['display_name' => 'Manager']);
        $role->givePermission($this->managerAuthPermission());
        $role->givePermission(
            Permission::query()->firstOrCreate(
                ['name' => PermissionName::ChatView->value],
                ['display_name' => 'Chat view']
            )
        );

        return $role;
    }

    private function managerAuthPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['name' => PermissionName::ManagerAuth->value],
            ['display_name' => 'Manager auth']
        );
    }
}
