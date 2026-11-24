<?php

namespace Tests\Feature\User;

use App\Enums\Locale;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\UserPaperStatus;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OwnProfileShortNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function sriLankaUser(): User
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
            'password' => bcrypt('password'),
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        // Reflects what a real request's auth middleware would hand the
        // action: a user freshly loaded from DB after the nationality
        // change was already committed by an earlier request, not the
        // in-memory instance from before the observer synced short_name.
        return $user->refresh();
    }

    public function testChangePasswordReturnsShortenedNameForSriLankaUser(): void
    {
        $user = $this->sriLankaUser();

        $this->actingAs($user, 'api')
            ->postJson('/user/password', [
                'current_password' => 'password',
                'password' => 'newpassword',
                'password_confirmation' => 'newpassword',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    public function testManageChangePasswordReturnsShortenedNameForSriLankaUser(): void
    {
        $user = $this->sriLankaUser();
        $this->grantManagerProfile($user);

        $this->actingAs($user, 'api')
            ->putJson('/manage/profile/password', [
                'current_password' => 'password',
                'password' => 'newpassword',
                'password_confirmation' => 'newpassword',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    public function testUpdateSettingsReturnsShortenedNameForSriLankaUser(): void
    {
        $user = $this->sriLankaUser();

        $this->actingAs($user, 'api')
            ->postJson('/user/settings', ['lang' => Locale::English->value])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    public function testManageChangeSettingsReturnsShortenedNameForSriLankaUser(): void
    {
        $user = $this->sriLankaUser();
        $this->grantManagerProfile($user);

        $this->actingAs($user, 'api')
            ->patchJson('/manage/profile/settings', ['lang' => Locale::English->value])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    public function testManageChangeInfoReturnsShortenedNameRecomputedFromNewNames(): void
    {
        $user = $this->sriLankaUser();
        $this->grantManagerProfile($user);

        $this->actingAs($user, 'api')
            ->patchJson('/manage/profile/info', [
                'first_name' => 'Deshan',
                'last_name' => 'Lokuruge',
                'first_name_kana' => 'デシャン',
                'last_name_kana' => 'ロクルゲ',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'DESHAN LOKURUGE');
    }

    public function testManageChangeProfilePictureReturnsShortenedNameForSriLankaUser(): void
    {
        $user = $this->sriLankaUser();
        $this->grantManagerProfile($user);

        $this->actingAs($user, 'api')
            ->putJson('/manage/profile/picture', [
                'image' => UploadedFile::fake()->image('avatar.jpg'),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    public function testManageDeleteProfilePictureReturnsShortenedNameForSriLankaUser(): void
    {
        $user = $this->sriLankaUser();
        $this->grantManagerProfile($user);

        $this->actingAs($user, 'api')
            ->deleteJson('/manage/profile/picture')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    private function grantManagerProfile(User $user): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::ManagerProfile->value],
            ['display_name' => 'Manager Profile']
        );
        $user->permissions()->attach($permission);
    }
}
