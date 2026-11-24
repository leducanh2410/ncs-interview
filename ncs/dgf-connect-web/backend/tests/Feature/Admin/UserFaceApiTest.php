<?php

namespace Tests\Feature\Admin;

use App\Contracts\FaceRecognitionProviderInterface;
use App\Enums\PermissionName;
use App\Enums\UserPaperType;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserFace;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Services\FaceRecognition\Responses\AddUserResponse;
use App\Services\FaceRecognition\Responses\RemoveUserResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class UserFaceApiTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = Mockery::mock(FaceRecognitionProviderInterface::class);
        $this->app->instance(FaceRecognitionProviderInterface::class, $this->mockProvider);
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

    private function createUser(): User
    {
        /** @var User $user */
        $user = User::factory()->create();

        return $user;
    }

    public function testIndexUnauthorizedForRegularUser(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->getJson('/admin/user-faces');

        $response->assertStatus(403);
    }

    public function testIndexAuthorizedForAdmin(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserFaceList);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/user-faces');

        $response->assertStatus(200);
    }

    public function testIndexReturnsPaginatedData(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserFaceList);

        UserFace::factory()->count(5)->create();

        $response = $this->actingAs($admin, 'api')->getJson('/admin/user-faces');

        $response->assertStatus(200)->assertJsonStructure([
            'data' => [
                'data' => [
                    '*' => ['id', 'user_id', 'vector_id', 'provider', 'user' => ['id', 'name', 'staff_no']],
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);
    }

    public function testStoreSuccess(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserFaceCreate);

        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/face.jpg',
        ]);

        $this->mockProvider->shouldReceive('addUser')->once()->andReturn(new AddUserResponse(true, 'vector-123'));

        $response = $this->actingAs($admin, 'api')->postJson('/admin/user-faces', [
            'user_id' => $user->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('user_faces', [
            'user_id' => $user->id,
            'vector_id' => 'vector-123',
        ]);
    }

    public function testStoreFailsIfAlreadyExists(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserFaceCreate);

        $userFace = UserFace::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson('/admin/user-faces', [
            'user_id' => $userFace->user_id,
        ]);

        $response->assertStatus(400);
    }

    public function testStoreFailsIfNoApprovedPortrait(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserFaceCreate);

        $user = User::factory()->create();
        // No paper item created

        $response = $this->actingAs($admin, 'api')->postJson('/admin/user-faces', [
            'user_id' => $user->id,
        ]);

        $response->assertStatus(400);
    }

    public function testUpdateSuccess(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserFaceUpdate);

        $userFace = UserFace::factory()->create(['vector_id' => 'old-vector']);

        $userPaper = UserPaper::factory()->create(['user_id' => $userFace->user_id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/new-face.jpg',
        ]);

        $this->mockProvider
            ->shouldReceive('removeUser')
            ->once()
            ->with((string) $userFace->user_id, 'old-vector')
            ->andReturn(new RemoveUserResponse(true, 1));

        $this->mockProvider->shouldReceive('addUser')->once()->andReturn(new AddUserResponse(true, 'new-vector'));

        $response = $this->actingAs($admin, 'api')->putJson("/admin/user-faces/{$userFace->user_id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_faces', [
            'user_id' => $userFace->user_id,
            'vector_id' => 'new-vector',
        ]);
    }

    public function testDestroySuccess(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserFaceDelete);

        $userFace = UserFace::factory()->create(['vector_id' => 'vector-to-delete']);

        $this->mockProvider
            ->shouldReceive('removeUser')
            ->once()
            ->with((string) $userFace->user_id, 'vector-to-delete')
            ->andReturn(new RemoveUserResponse(true, 1));

        $response = $this->actingAs($admin, 'api')->deleteJson("/admin/user-faces/{$userFace->user_id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('user_faces', [
            'user_id' => $userFace->user_id,
        ]);
    }
}
