<?php

namespace Tests\Feature\Api\User;

use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\TestCase;

class GetPapersTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;

    public function testReturnsPendingItemsWithNullFolder(): void
    {
        $user = $this->createUser();
        $this->attachUserRole($user);

        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'folder' => null,
            'approved_at' => null,
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/papers');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function testReturnsApprovedCurrentItems(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value]);
        $this->attachUserRole($user);

        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'folder' => 'current',
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/papers');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function testReturnsBothPendingAndApprovedItemsTogether(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $this->attachUserRole($user);

        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'folder' => 'current',
            'approved_at' => now()->subDay(),
        ]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::Passport->value,
            'folder' => null,
            'approved_at' => null,
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/papers');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function testExcludesDeletedFolderItems(): void
    {
        $user = $this->createUser();
        $this->attachUserRole($user);

        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'folder' => 'deleted',
            'approved_at' => null,
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/papers');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.items'));
    }

    public function testExcludesSoftDeletedItems(): void
    {
        $user = $this->createUser();
        $this->attachUserRole($user);

        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'folder' => null,
            'approved_at' => null,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/papers');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.items'));
    }

    public function testReturnsRejectedItems(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Rejected->value]);
        $this->attachUserRole($user);

        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'folder' => 'rejected',
            'approved_at' => null,
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/papers');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function testFiltersByTypeWhenProvided(): void
    {
        $user = $this->createUser();
        $this->attachUserRole($user);

        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'folder' => null,
            'approved_at' => null,
        ]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::Passport->value,
            'folder' => null,
            'approved_at' => null,
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/papers?type=' . UserPaperType::PortraitImage->value);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame(UserPaperType::PortraitImage->value, $response->json('data.items.0.type'));
    }
}
