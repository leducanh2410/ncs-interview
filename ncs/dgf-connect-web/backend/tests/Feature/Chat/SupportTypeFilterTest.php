<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatParticipantRole;
use App\Enums\PermissionName;
use App\Enums\SupportType;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class SupportTypeFilterTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testFilterBySupportType(): void
    {
        $actor = $this->actor();
        $salary = $this->makeSupportConversation(SupportType::Salary->value);
        $shift = $this->makeSupportConversation(SupportType::Shift->value);
        $noType = $this->makeSupportConversation(null);

        $response = $this->actingAs($actor, 'api')->getJson(
            '/admin/chat/support-conversations?status=all&support_type=' . SupportType::Salary->value
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($salary->id));
        $this->assertFalse($ids->contains($shift->id));
        $this->assertFalse($ids->contains($noType->id));
    }

    public function testFilterBySupportTypeCombinedWithStatusFilter(): void
    {
        $actor = $this->actor();
        $openShift = $this->makeSupportConversation(SupportType::Shift->value);
        $this->addParticipant($openShift, $actor, ['role' => ChatParticipantRole::Admin->value]);

        $salaryConversation = $this->makeSupportConversation(SupportType::Salary->value);
        $this->addParticipant($salaryConversation, $actor, ['role' => ChatParticipantRole::Admin->value]);

        $response = $this->actingAs($actor, 'api')->getJson(
            '/admin/chat/support-conversations?status=assigned&support_type=' . SupportType::Shift->value
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($openShift->id));
        $this->assertFalse($ids->contains($salaryConversation->id));
    }

    public function testFilterByOtherSupportType(): void
    {
        $actor = $this->actor();
        $other = $this->makeSupportConversation(SupportType::Other->value);
        $shift = $this->makeSupportConversation(SupportType::Shift->value);

        $response = $this->actingAs($actor, 'api')->getJson(
            '/admin/chat/support-conversations?status=all&support_type=' . SupportType::Other->value
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($other->id));
        $this->assertFalse($ids->contains($shift->id));
    }

    public function testFilterSupportByConversationStatus(): void
    {
        $actor = $this->actor();
        $open = $this->makeSupportConversation(SupportType::Shift->value);
        $closed = ChatConversation::factory()
            ->support()
            ->create(['support_type' => SupportType::Shift->value, 'status' => 'closed']);

        $openResp = $this->actingAs($actor, 'api')->getJson(
            '/admin/chat/support-conversations?status=all&conversation_status=open'
        );
        $openResp->assertOk();
        $openIds = collect($openResp->json('data.data'))->pluck('id');
        $this->assertTrue($openIds->contains($open->id));
        $this->assertFalse($openIds->contains($closed->id));

        $closedResp = $this->actingAs($actor, 'api')->getJson(
            '/admin/chat/support-conversations?status=all&conversation_status=closed'
        );
        $closedResp->assertOk();
        $closedIds = collect($closedResp->json('data.data'))->pluck('id');
        $this->assertTrue($closedIds->contains($closed->id));
        $this->assertFalse($closedIds->contains($open->id));

        // 'all' (or omitted) returns both.
        $allResp = $this->actingAs($actor, 'api')->getJson(
            '/admin/chat/support-conversations?status=all&conversation_status=all'
        );
        $allIds = collect($allResp->json('data.data'))->pluck('id');
        $this->assertTrue($allIds->contains($open->id));
        $this->assertTrue($allIds->contains($closed->id));
    }

    public function testFilterByInvalidSupportTypeReturns422(): void
    {
        $actor = $this->actor();

        $this->actingAs($actor, 'api')
            ->getJson('/admin/chat/support-conversations?support_type=invalid_type')
            ->assertStatus(422);
    }

    public function testResponseItemIncludesSupportTypeField(): void
    {
        $actor = $this->actor();
        $this->makeSupportConversation(SupportType::Document->value);

        $response = $this->actingAs($actor, 'api')->getJson('/admin/chat/support-conversations?status=all');

        $response->assertOk();
        $item = collect($response->json('data.data'))->first();
        $this->assertArrayHasKey('support_type', $item);
        $this->assertSame(SupportType::Document->value, $item['support_type']);
    }

    private function actor(): User
    {
        return $this->createUserWithPermissions([PermissionName::ChatAssign]);
    }

    private function makeSupportConversation(?string $supportType = null): ChatConversation
    {
        return ChatConversation::factory()
            ->support()
            ->create(['support_type' => $supportType]);
    }
}
