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

class UserPaperMoveTest extends TestCase
{
    use RefreshDatabase;

    public function testAdminCanMoveSinglePaperItemBetweenCurrentAndOldFolders(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'approved_at' => now(),
            'rejected_at' => null,
            'folder' => UserPaperItemFolder::Current->value,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/move", [
            'ids' => [$item->id],
            'folder' => UserPaperItemFolder::Old->value,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(UserPaperItemFolder::Old->value, $item->fresh()->folder);
    }

    public function testAdminCanMoveMultiplePaperItemsBetweenCurrentAndOldFolders(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item1 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'approved_at' => now(),
            'rejected_at' => null,
            'folder' => UserPaperItemFolder::Current->value,
        ]);
        $item2 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'approved_at' => now(),
            'rejected_at' => null,
            'folder' => UserPaperItemFolder::Current->value,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/move", [
            'ids' => [$item1->id, $item2->id],
            'folder' => UserPaperItemFolder::Old->value,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(UserPaperItemFolder::Old->value, $item1->fresh()->folder);
        $this->assertEquals(UserPaperItemFolder::Old->value, $item2->fresh()->folder);
    }

    public function testAdminCannotMovePaperItemsToRejectedFolder(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'approved_at' => now(),
            'rejected_at' => null,
            'folder' => UserPaperItemFolder::Current->value,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/move", [
            'ids' => [$item->id],
            'folder' => UserPaperItemFolder::Rejected->value,
        ]);

        $response->assertStatus(422);
        $this->assertEquals(UserPaperItemFolder::Current->value, $item->fresh()->folder);
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
