<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatConversationType;
use App\Enums\RoleName;
use App\Enums\SupportType;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class ConversationDetailTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    public function testReturnsConversationDetailForActiveParticipant(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $conversation->id);
    }

    public function testExposesTheShiftLinkSoAnOrphanedShiftGroupCanBeRecognised(): void
    {
        $user = User::factory()->create();
        $shift = $this->makeShift();
        $linked = $this->makeConversation([
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $shift->id,
        ]);
        $orphaned = $this->makeConversation([
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => null,
        ]);
        $this->addParticipant($linked, $user);
        $this->addParticipant($orphaned, $user);

        $this->actingAs($user, 'api')
            ->getJson("/user/chat/conversations/{$linked->id}")
            ->assertOk()
            ->assertJsonPath('data.shift_id', $shift->id);
        $this->actingAs($user, 'api')
            ->getJson("/user/chat/conversations/{$orphaned->id}")
            ->assertOk()
            ->assertJsonPath('data.shift_id', null);
    }

    public function testReturns404ForNonParticipantOnDetail(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}");

        $response->assertStatus(404);
    }

    public function testReturns404ForRemovedParticipantOnDetail(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user, ['status' => 'removed', 'removed_at' => now()]);

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}");

        $response->assertStatus(404);
    }

    public function testDetailIncludesParticipantsAndPermissionFlags(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation(['employee_can_send_message' => false]);
        $this->addParticipant($conversation, $user);
        $this->addParticipant($conversation, $other, ['role' => 'admin']);

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.permissions.employee_can_send_message', false);
        $this->assertCount(2, $response->json('data.participants'));
    }

    public function testDetailIncludesSupportTypeField(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation(['support_type' => SupportType::Shift->value]);
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.support_type', SupportType::Shift->value);
    }

    public function testDetailSupportTypeIsNullWhenNotSet(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation(['support_type' => null]);
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.support_type', null);
    }

    public function testParticipantsIncludeStaffNo(): void
    {
        $user = User::factory()->create(['staff_no' => 'E000001']);
        $other = User::factory()->create(['staff_no' => null]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $this->addParticipant($conversation, $other, ['role' => 'admin']);

        $response = $this->actingAs($user, 'api')->getJson("/user/chat/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $participants = $response->json('data.participants');
        $byUserId = collect($participants)->keyBy('user_id');

        $this->assertSame('E000001', $byUserId[$user->id]['staff_no']);
        $this->assertNull($byUserId[$other->id]['staff_no']);
    }

    public function testUserConversationDetailDoesNotExposeGlobalAdminFlag(): void
    {
        // The Global Admin badge is admin-UI only; the employee-facing endpoint must not leak
        // which participants hold the system-level global_admin role.
        $globalAdmin = User::factory()->create();
        $role = Role::query()->firstOrCreate(
            ['name' => RoleName::GlobalAdmin->value],
            ['display_name' => 'Global Admin']
        );
        $globalAdmin->addRole($role);

        $regular = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $regular);
        $this->addParticipant($conversation, $globalAdmin, ['role' => 'admin']);

        $response = $this->actingAs($regular, 'api')->getJson("/user/chat/conversations/{$conversation->id}");

        $response->assertStatus(200);

        foreach ($response->json('data.participants') as $participant) {
            $this->assertArrayNotHasKey('is_global_admin', $participant);
        }
    }

    private function makeShift(): Shift
    {
        $workplace = Workplace::factory()->create();
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        return Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => User::factory()->create()->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'temp_total' => 1,
            'fixed_total' => 0,
        ]);
    }
}
