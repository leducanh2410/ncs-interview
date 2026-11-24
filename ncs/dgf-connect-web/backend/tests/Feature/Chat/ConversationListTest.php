<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatSystemConversationKey;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class ConversationListTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    public function testListsOnlyConversationsWhereUserIsActiveParticipant(): void
    {
        $user = User::factory()->create();

        $mine = $this->makeConversation();
        $this->addParticipant($mine, $user);

        $other = $this->makeConversation();
        $this->addParticipant($other, User::factory()->create());

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function testExcludesConversationsWhereUserWasRemoved(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user, ['status' => 'removed', 'removed_at' => now()]);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
    }

    public function testReturnsUnreadCountFromParticipantRow(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user, ['unread_count' => 4]);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertStatus(200);
        $this->assertSame(4, $response->json('data.data.0.unread_count'));
    }

    public function testReturnsLastMessagePreview(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $message = $this->addMessage($conversation, $user, ['body' => 'hello world']);
        $conversation
            ->forceFill([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ])
            ->save();

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertStatus(200);
        $this->assertSame($message->id, $response->json('data.data.0.last_message.id'));
        $this->assertSame('hello world', $response->json('data.data.0.last_message.body'));
    }

    public function testOrdersConversationsByLastMessageAtDesc(): void
    {
        $user = User::factory()->create();

        $older = $this->makeConversation(['last_message_at' => now()->subDay()]);
        $this->addParticipant($older, $user);
        $newer = $this->makeConversation(['last_message_at' => now()]);
        $this->addParticipant($newer, $user);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertStatus(200);
        $this->assertSame($newer->id, $response->json('data.data.0.id'));
        $this->assertSame($older->id, $response->json('data.data.1.id'));
    }

    public function testSearchByParticipantFirstName(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create(['first_name' => 'Thai', 'last_name' => 'Nguyen']);
        $other = User::factory()->create(['first_name' => 'Binh', 'last_name' => 'Tran']);

        $matched = $this->makeConversation();
        $this->addParticipant($matched, $user);
        $this->addParticipant($matched, $target);

        $notMatched = $this->makeConversation();
        $this->addParticipant($notMatched, $user);
        $this->addParticipant($notMatched, $other);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?search=thai');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchByShortNameMatchesReversedFallbackName(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create(['first_name' => 'Deshan', 'last_name' => 'Lokuruge']);
        $target->info->update(['nationality' => Nationality::SriLanka->value]);
        $other = User::factory()->create(['first_name' => 'Taro', 'last_name' => 'Sato']);

        $matched = $this->makeConversation();
        $this->addParticipant($matched, $user);
        $this->addParticipant($matched, $target);

        $notMatched = $this->makeConversation();
        $this->addParticipant($notMatched, $user);
        $this->addParticipant($notMatched, $other);

        // Raw CONCAT(last_name, first_name) is "Lokuruge Deshan"; searching what's displayed
        // ("DESHAN LOKURUGE", the reversed-fallback short_name) must also match.
        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?search=DESHAN LOKURUGE');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchByParticipantLastName(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create(['first_name' => 'Van', 'last_name' => 'Nguyen']);
        $other = User::factory()->create(['first_name' => 'Van', 'last_name' => 'Tran']);

        $matched = $this->makeConversation();
        $this->addParticipant($matched, $user);
        $this->addParticipant($matched, $target);

        $notMatched = $this->makeConversation();
        $this->addParticipant($notMatched, $user);
        $this->addParticipant($notMatched, $other);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?search=nguyen');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchByParticipantStaffNo(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create(['staff_no' => 'EMP001']);
        $other = User::factory()->create(['staff_no' => 'EMP999']);

        $matched = $this->makeConversation();
        $this->addParticipant($matched, $user);
        $this->addParticipant($matched, $target);

        $notMatched = $this->makeConversation();
        $this->addParticipant($notMatched, $user);
        $this->addParticipant($notMatched, $other);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?search=EMP001');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create(['first_name' => 'Thai', 'last_name' => 'Nguyen', 'staff_no' => 'EMP042']);

        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $this->addParticipant($conversation, $target);

        foreach (['THAI', 'thai', 'Nguy', 'emp042', 'EMP042'] as $query) {
            $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?search=' . $query);
            $response->assertStatus(200);
            $ids = collect($response->json('data.data'))->pluck('id')->all();
            $this->assertContains($conversation->id, $ids, "Search '{$query}' should match");
        }
    }

    public function testSearchDoesNotMatchCurrentUserOwnName(): void
    {
        $user = User::factory()->create(['first_name' => 'Thai', 'last_name' => 'Nguyen']);
        $other = User::factory()->create(['first_name' => 'Binh', 'last_name' => 'Tran']);

        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $this->addParticipant($conversation, $other);

        // Searching the current user's own name should not match (search targets other participants)
        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?search=thai');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
    }

    public function testEmptySearchReturnsAllConversations(): void
    {
        $user = User::factory()->create();

        $conv1 = $this->makeConversation();
        $this->addParticipant($conv1, $user);
        $conv2 = $this->makeConversation();
        $this->addParticipant($conv2, $user);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?search=');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    public function testSearchWithStringZeroFiltersCorrectly(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create(['staff_no' => 'E000042']);
        $other = User::factory()->create(['staff_no' => 'EMP999']);

        $matched = $this->makeConversation();
        $this->addParticipant($matched, $user);
        $this->addParticipant($matched, $target);

        $notMatched = $this->makeConversation();
        $this->addParticipant($notMatched, $user);
        $this->addParticipant($notMatched, $other);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?search=0');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchExcludesRemovedParticipants(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create(['first_name' => 'Thai', 'last_name' => 'Nguyen']);

        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $this->addParticipant($conversation, $target, ['status' => 'removed', 'removed_at' => now()]);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?search=thai');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
    }

    public function testDefaultsToOpenConversationsOnly(): void
    {
        $user = User::factory()->create();

        $open = $this->makeConversation(['status' => 'open']);
        $this->addParticipant($open, $user);
        $closed = $this->makeConversation(['status' => 'closed']);
        $this->addParticipant($closed, $user);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($open->id, $ids);
        $this->assertNotContains($closed->id, $ids);
    }

    public function testFilterByClosedStatus(): void
    {
        $user = User::factory()->create();

        $open = $this->makeConversation(['status' => 'open']);
        $this->addParticipant($open, $user);
        $closed = $this->makeConversation(['status' => 'closed']);
        $this->addParticipant($closed, $user);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?status=closed');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($closed->id, $ids);
        $this->assertNotContains($open->id, $ids);
    }

    public function testStatusAllReturnsBothOpenAndClosed(): void
    {
        $user = User::factory()->create();

        $open = $this->makeConversation(['status' => 'open']);
        $this->addParticipant($open, $user);
        $closed = $this->makeConversation(['status' => 'closed']);
        $this->addParticipant($closed, $user);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?status=all');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($open->id, $ids);
        $this->assertContains($closed->id, $ids);
    }

    public function testInvalidStatusReturns422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->getJson('/user/chat/conversations?status=archived')->assertStatus(422);
    }

    public function testFilterByCategoryChatExcludesSupport(): void
    {
        $user = User::factory()->create();
        $chat = $this->makeConversation(['type' => 'admin_group']);
        $this->addParticipant($chat, $user);
        $support = $this->makeConversation(['type' => 'support']);
        $this->addParticipant($support, $user);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?category=chat&status=all');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($chat->id, $ids);
        $this->assertNotContains($support->id, $ids);
    }

    public function testFilterByCategorySupportExcludesChat(): void
    {
        $user = User::factory()->create();
        $chat = $this->makeConversation(['type' => 'admin_group']);
        $this->addParticipant($chat, $user);
        $support = $this->makeConversation(['type' => 'guest_support']);
        $this->addParticipant($support, $user);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations?category=support&status=all');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($support->id, $ids);
        $this->assertNotContains($chat->id, $ids);
    }

    public function testReturnsSupportTypeForSupportConversation(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation(['type' => 'support', 'support_type' => 'salary']);
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertStatus(200);
        $this->assertSame('salary', $response->json('data.data.0.support_type'));
    }

    public function testReturnsSupportTypeNullForNonSupportConversation(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation(['type' => 'group', 'support_type' => null]);
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertStatus(200);
        $this->assertNull($response->json('data.data.0.support_type'));
    }

    public function testSupportTypeIsIncludedInResponseSchema(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertStatus(200);
        $this->assertArrayHasKey('support_type', $response->json('data.data.0'));
    }

    public function testAllSupportTypesAreReturnedCorrectly(): void
    {
        $user = User::factory()->create();

        foreach (['document', 'shift', 'salary', 'transportation', 'other'] as $type) {
            $conversation = $this->makeConversation(['type' => 'support', 'support_type' => $type]);
            $this->addParticipant($conversation, $user);
        }

        $response = $this->actingAs($user, 'api')->getJson('/user/chat/conversations');

        $response->assertStatus(200);
        $returnedTypes = collect($response->json('data.data'))->pluck('support_type')->sort()->values()->all();
        $this->assertSame(['document', 'other', 'salary', 'shift', 'transportation'], $returnedTypes);
    }

    public function testTheInternalAdminGroupIsPinnedAboveMoreRecentConversations(): void
    {
        $admin = $this->adminOfTheInternalGroup();
        $internal = ChatConversation::query()
            ->where('system_key', ChatSystemConversationKey::AdminInternal->value)
            ->firstOrFail();

        foreach (range(1, 3) as $offset) {
            $busy = $this->makeConversation(['last_message_at' => now()->addMinutes($offset)]);
            $this->addParticipant($busy, $admin);
        }

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/conversations');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertCount(4, $ids);
        $this->assertSame($internal->id, $ids[0]);
    }

    private function adminOfTheInternalGroup(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'manager'], ['display_name' => 'Manager']);
        foreach ([PermissionName::ManagerAuth, PermissionName::ChatView] as $permission) {
            $role->givePermission(
                Permission::query()->firstOrCreate(
                    ['name' => $permission->value],
                    ['display_name' => $permission->value]
                )
            );
        }

        $user = User::factory()->create();
        $user->addRole($role);

        return $user->fresh();
    }
}
