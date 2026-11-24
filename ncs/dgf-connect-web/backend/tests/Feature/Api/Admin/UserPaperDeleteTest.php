<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use App\Enums\UserPaperItemFolder;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPaperDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function testAdminMarksPaperItemsAsDeleted(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperDelete);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item1 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'folder' => UserPaperItemFolder::Current->value,
            'deleted_at' => null,
        ]);
        $item2 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'folder' => UserPaperItemFolder::Old->value,
            'deleted_at' => null,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/delete", [
            'ids' => [$item1->id, $item2->id],
        ]);

        $response->assertStatus(200);

        $this->assertEquals(UserPaperItemFolder::Deleted->value, $item1->fresh()->folder);
        $this->assertEquals(UserPaperItemFolder::Deleted->value, $item2->fresh()->folder);
        $this->assertNotNull($item1->fresh()->deleted_at);
        $this->assertNotNull($item2->fresh()->deleted_at);
    }

    public function testAdminCannotDeletePaperItemsFromOtherUser(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperDelete);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUserPaper = UserPaper::factory()->create(['user_id' => $otherUser->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $otherUserPaper->id,
            'folder' => UserPaperItemFolder::Current->value,
            'deleted_at' => null,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/delete", [
            'ids' => [$item->id],
        ]);

        $response->assertStatus(404);
        $this->assertEquals(UserPaperItemFolder::Current->value, $item->fresh()->folder);
        $this->assertNull($item->fresh()->deleted_at);
    }

    private function createAdminWithPermission(PermissionName $permissionName): User
    {
        $admin = User::factory()->create();
        $permission = Permission::create([
            'name' => $permissionName->value,
            'display_name' => $permissionName->value,
        ]);
        $admin->permissions()->attach($permission);

        return $admin;
    }
}
