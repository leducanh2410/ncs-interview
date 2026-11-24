<?php

namespace Tests\Feature\Chat\Admin;

use App\Enums\ChatBannedKeywordAction;
use App\Enums\PermissionName;
use App\Models\ChatBannedKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AdminBannedKeywordTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('chat:banned_keywords_v2');
    }

    // -------------------------------------------------------------------------
    // GET /admin/chat/banned-keywords
    // -------------------------------------------------------------------------

    public function testIndexRequiresConfigListPermission(): void
    {
        $user = $this->createUserWithPermissions([]);

        $this->actingAs($user, 'api')->getJson('/admin/chat/banned-keywords')->assertStatus(403);
    }

    public function testIndexReturnsAllKeywords(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigList]);
        ChatBannedKeyword::factory()->count(3)->create();

        $response = $this->actingAs($user, 'api')->getJson('/admin/chat/banned-keywords');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    // -------------------------------------------------------------------------
    // POST /admin/chat/banned-keywords
    // -------------------------------------------------------------------------

    public function testStoreRequiresConfigEditPermission(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigList]);

        $this->actingAs($user, 'api')
            ->postJson('/admin/chat/banned-keywords', [
                'keyword' => 'test',
                'action' => 'mask',
            ])
            ->assertStatus(403);
    }

    public function testStoreCreatesKeywordAndInvalidatesCache(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);
        Cache::put('chat:banned_keywords_v2', ['block' => [], 'mask' => ['old']], 300);

        $response = $this->actingAs($user, 'api')->postJson('/admin/chat/banned-keywords', [
            'keyword' => 'lương',
            'locale' => 'vi',
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.keyword', 'lương');
        $response->assertJsonPath('data.locale', 'vi');
        $response->assertJsonPath('data.action', 'mask');
        $this->assertDatabaseHas('chat_banned_keywords', ['keyword' => 'lương']);
        $this->assertNull(Cache::get('chat:banned_keywords_v2'));
    }

    public function testStoreFailsValidationWhenKeywordMissing(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);

        $this->actingAs($user, 'api')
            ->postJson('/admin/chat/banned-keywords', ['action' => 'mask'])
            ->assertStatus(422);
    }

    public function testStoreFailsValidationWithInvalidAction(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);

        $this->actingAs($user, 'api')
            ->postJson('/admin/chat/banned-keywords', ['keyword' => 'test', 'action' => 'invalid'])
            ->assertStatus(422);
    }

    public function testStoreFailsValidationWithUnsupportedLocale(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);

        $this->actingAs($user, 'api')
            ->postJson('/admin/chat/banned-keywords', [
                'keyword' => 'test',
                'action' => ChatBannedKeywordAction::Mask->value,
                'locale' => 'fr',
            ])
            ->assertStatus(422);
    }

    public function testUpdateFailsValidationWithUnsupportedLocale(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);
        $keyword = ChatBannedKeyword::factory()->create();

        $this->actingAs($user, 'api')
            ->patchJson("/admin/chat/banned-keywords/{$keyword->id}", ['locale' => 'fr'])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // PATCH /admin/chat/banned-keywords/{id}
    // -------------------------------------------------------------------------

    public function testUpdateRequiresConfigEditPermission(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigList]);
        $keyword = ChatBannedKeyword::factory()->create();

        $this->actingAs($user, 'api')
            ->patchJson("/admin/chat/banned-keywords/{$keyword->id}", ['is_active' => false])
            ->assertStatus(403);
    }

    public function testUpdateModifiesKeywordAndInvalidatesCache(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);
        $keyword = ChatBannedKeyword::factory()->create(['keyword' => 'old', 'is_active' => true]);
        Cache::put('chat:banned_keywords_v2', ['block' => [], 'mask' => ['old']], 300);

        $response = $this->actingAs($user, 'api')->patchJson("/admin/chat/banned-keywords/{$keyword->id}", [
            'keyword' => 'new',
            'is_active' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.keyword', 'new');
        $response->assertJsonPath('data.is_active', false);
        $this->assertNull(Cache::get('chat:banned_keywords_v2'));
    }

    public function testUpdateReturns404ForNonExistentKeyword(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);

        $this->actingAs($user, 'api')
            ->patchJson('/admin/chat/banned-keywords/9999', ['is_active' => false])
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // DELETE /admin/chat/banned-keywords/{id}
    // -------------------------------------------------------------------------

    public function testDestroyRequiresConfigEditPermission(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigList]);
        $keyword = ChatBannedKeyword::factory()->create();

        $this->actingAs($user, 'api')
            ->deleteJson("/admin/chat/banned-keywords/{$keyword->id}")
            ->assertStatus(403);
    }

    public function testDestroyDeletesKeywordAndInvalidatesCache(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);
        $keyword = ChatBannedKeyword::factory()->create();
        Cache::put('chat:banned_keywords_v2', ['block' => [], 'mask' => [$keyword->keyword]], 300);

        $this->actingAs($user, 'api')
            ->deleteJson("/admin/chat/banned-keywords/{$keyword->id}")
            ->assertOk();

        $this->assertDatabaseMissing('chat_banned_keywords', ['id' => $keyword->id]);
        $this->assertNull(Cache::get('chat:banned_keywords_v2'));
    }

    public function testDestroyReturns404ForNonExistentKeyword(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ConfigEdit]);

        $this->actingAs($user, 'api')->deleteJson('/admin/chat/banned-keywords/9999')->assertStatus(404);
    }
}
