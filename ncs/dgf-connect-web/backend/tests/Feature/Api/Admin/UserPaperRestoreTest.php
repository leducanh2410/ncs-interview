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

class UserPaperRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function testAdminCanRestoreDeletedPaperItems(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperDelete);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item1 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'folder' => UserPaperItemFolder::Deleted->value,
            'deleted_from_folder' => UserPaperItemFolder::Rejected->value,
            'deleted_at' => now(),
        ]);
        $item2 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'folder' => UserPaperItemFolder::Deleted->value,
            'deleted_from_folder' => UserPaperItemFolder::Current->value,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/restore", [
            'ids' => [$item1->id, $item2->id],
        ]);

        $response->assertStatus(200);

        $this->assertEquals(UserPaperItemFolder::Rejected->value, $item1->fresh()->folder);
        $this->assertEquals(UserPaperItemFolder::Current->value, $item2->fresh()->folder);
        $this->assertNull($item1->fresh()->deleted_from_folder);
        $this->assertNull($item2->fresh()->deleted_from_folder);
        $this->assertNull($item1->fresh()->deleted_at);
        $this->assertNull($item2->fresh()->deleted_at);
    }

    public function testAdminCannotRestoreNonDeletedPaperItems(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperDelete);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'folder' => UserPaperItemFolder::Current->value,
            'deleted_at' => null,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/restore", [
            'ids' => [$item->id],
        ]);

        $response->assertStatus(422);
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
