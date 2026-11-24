<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use App\Models\GuestSupportSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class SupportConversationSearchTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testSearchByEmployeeFirstName(): void
    {
        $admin = $this->adminWithAssignPermission();
        $employee = User::factory()->create(['first_name' => 'Thai', 'last_name' => 'Nguyen']);
        $other = User::factory()->create(['first_name' => 'Binh', 'last_name' => 'Tran']);

        $matched = $this->makeEmployeeSupportConversation($employee);
        $notMatched = $this->makeEmployeeSupportConversation($other);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/support-conversations?status=all&search=thai');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchByShortNameMatchesReversedFallbackName(): void
    {
        $admin = $this->adminWithAssignPermission();
        $employee = User::factory()->create(['first_name' => 'Deshan', 'last_name' => 'Lokuruge']);
        $employee->info->update(['nationality' => Nationality::SriLanka->value]);
        $other = User::factory()->create(['first_name' => 'Taro', 'last_name' => 'Sato']);

        $matched = $this->makeEmployeeSupportConversation($employee);
        $notMatched = $this->makeEmployeeSupportConversation($other);

        // Raw CONCAT(last_name, first_name) is "Lokuruge Deshan"; searching what's displayed
        // ("DESHAN LOKURUGE", the reversed-fallback short_name) must also match.
        $response = $this->actingAs($admin, 'api')->getJson(
            '/admin/chat/support-conversations?status=all&search=DESHAN LOKURUGE'
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchByEmployeeLastName(): void
    {
        $admin = $this->adminWithAssignPermission();
        $employee = User::factory()->create(['first_name' => 'Van', 'last_name' => 'Nguyen']);
        $other = User::factory()->create(['first_name' => 'Van', 'last_name' => 'Tran']);

        $matched = $this->makeEmployeeSupportConversation($employee);
        $notMatched = $this->makeEmployeeSupportConversation($other);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/admin/chat/support-conversations?status=all&search=nguyen'
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchByEmployeeStaffNo(): void
    {
        $admin = $this->adminWithAssignPermission();
        $employee = User::factory()->create(['staff_no' => 'EMP001']);
        $other = User::factory()->create(['staff_no' => 'EMP999']);

        $matched = $this->makeEmployeeSupportConversation($employee);
        $notMatched = $this->makeEmployeeSupportConversation($other);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/admin/chat/support-conversations?status=all&search=EMP001'
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchByGuestName(): void
    {
        $admin = $this->adminWithAssignPermission();

        $matched = $this->makeGuestSupportConversation('Yamada Hanako');
        $notMatched = $this->makeGuestSupportConversation('Tanaka Jiro');

        $response = $this->actingAs($admin, 'api')->getJson(
            '/admin/chat/support-conversations?status=all&search=yamada'
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $admin = $this->adminWithAssignPermission();
        $employee = User::factory()->create(['first_name' => 'Thai', 'last_name' => 'Nguyen', 'staff_no' => 'EMP042']);
        $conversation = $this->makeEmployeeSupportConversation($employee);

        foreach (['THAI', 'thai', 'Nguy', 'emp042', 'EMP042'] as $query) {
            $response = $this->actingAs($admin, 'api')->getJson(
                '/admin/chat/support-conversations?status=all&search=' . $query
            );
            $response->assertOk();
            $ids = collect($response->json('data.data'))->pluck('id')->all();
            $this->assertContains($conversation->id, $ids, "Search '{$query}' should match");
        }
    }

    public function testEmptySearchReturnsAllConversations(): void
    {
        $admin = $this->adminWithAssignPermission();
        $emp1 = User::factory()->create();
        $emp2 = User::factory()->create();

        $conv1 = $this->makeEmployeeSupportConversation($emp1);
        $conv2 = $this->makeEmployeeSupportConversation($emp2);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/support-conversations?status=all&search=');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($conv1->id, $ids);
        $this->assertContains($conv2->id, $ids);
    }

    public function testSearchWithStringZeroFiltersCorrectly(): void
    {
        $admin = $this->adminWithAssignPermission();
        $employee = User::factory()->create(['staff_no' => 'E000042']);
        $other = User::factory()->create(['staff_no' => 'EMP999']);

        $matched = $this->makeEmployeeSupportConversation($employee);
        $notMatched = $this->makeEmployeeSupportConversation($other);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/support-conversations?status=all&search=0');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matched->id, $ids);
        $this->assertNotContains($notMatched->id, $ids);
    }

    public function testSearchWorksAlongsideStatusFilter(): void
    {
        $admin = $this->adminWithAssignPermission();
        $employee = User::factory()->create(['first_name' => 'Thai', 'last_name' => 'Nguyen']);

        $unassigned = $this->makeEmployeeSupportConversation($employee);
        $assigned = $this->makeEmployeeSupportConversation($employee);
        $this->addParticipant($assigned, $admin, ['role' => ChatParticipantRole::Admin->value]);

        $responseUnassigned = $this->actingAs($admin, 'api')->getJson(
            '/admin/chat/support-conversations?status=unassigned&search=thai'
        );
        $responseUnassigned->assertOk();
        $ids = collect($responseUnassigned->json('data.data'))->pluck('id')->all();
        $this->assertContains($unassigned->id, $ids);
        $this->assertNotContains($assigned->id, $ids);

        $responseAssigned = $this->actingAs($admin, 'api')->getJson(
            '/admin/chat/support-conversations?status=assigned&search=thai'
        );
        $responseAssigned->assertOk();
        $ids = collect($responseAssigned->json('data.data'))->pluck('id')->all();
        $this->assertContains($assigned->id, $ids);
        $this->assertNotContains($unassigned->id, $ids);
    }

    private function makeEmployeeSupportConversation(User $employee): ChatConversation
    {
        $conversation = ChatConversation::factory()
            ->support()
            ->create(['created_by_user_id' => $employee->id]);
        $this->addParticipant($conversation, $employee, ['role' => ChatParticipantRole::Employee->value]);

        return $conversation;
    }

    private function makeGuestSupportConversation(string $guestName): ChatConversation
    {
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);
        $session = GuestSupportSession::query()->create([
            'device_id' => Str::uuid(),
            'platform' => 'ios',
            'guest_name' => $guestName,
            'guest_email' => 'guest@example.com',
            'session_token' => Crypt::encryptString(Str::random(64)),
            'session_token_hash' => hash_hmac('sha256', Str::random(64), config('app.key')),
            'conversation_id' => $conversation->id,
        ]);
        $conversation->forceFill(['guest_support_session_id' => $session->id])->save();

        return $conversation;
    }

    private function adminWithAssignPermission(): User
    {
        return $this->createUserWithPermissions([PermissionName::ChatAssign]);
    }
}
