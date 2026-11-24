<?php

namespace Tests\Feature\Auth;

use App\Enums\FaceRecognitionProviderName;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserFace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $userRole = Role::create([
            'name' => RoleName::User->value,
            'display_name' => 'User',
            'description' => 'Regular user',
        ]);

        $this->user = User::factory()->create();
        $this->user->addRole($userRole);

        config(['services.face_recognition.provider' => FaceRecognitionProviderName::Rekognition->value]);
    }

    public function testAuthCheckReturnsFaceRegisteredTrueWhenUserFaceExists(): void
    {
        UserFace::factory()->create([
            'user_id' => $this->user->id,
            'provider' => FaceRecognitionProviderName::Rekognition->value,
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/auth/check');

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.face_registered', true);
    }

    public function testAuthCheckReturnsFaceRegisteredFalseWhenNoUserFace(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/auth/check');

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.face_registered', false);
    }

    public function testManageAuthCheckDoesNotIncludeFaceRegistered(): void
    {
        $permission = Permission::create([
            'name' => PermissionName::ManagerAuth->value,
            'display_name' => 'Manager Auth',
        ]);
        $this->user->permissions()->attach($permission);

        $response = $this->actingAs($this->user, 'api')->getJson('/manage/auth/check');

        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.user.face_registered');
    }
}
