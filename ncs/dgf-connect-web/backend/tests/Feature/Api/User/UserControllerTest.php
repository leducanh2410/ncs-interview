<?php

namespace Tests\Feature\Api\User;

use App\Enums\ErrorCode;
use App\Enums\Gender;
use App\Enums\Nationality;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Services\AuthService;
use App\Services\User\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();

        $this->user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value]);
        $this->attachUserRole($this->user);
    }

    // -------------------------------------------------------------------------
    // getUserInfo
    // -------------------------------------------------------------------------

    public function testGetUserInfoRequiresAuthentication(): void
    {
        $this->getJson('/user/profile')->assertStatus(401);
    }

    public function testGetUserInfoReturnsCurrentUser(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/user/profile');

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $this->user->id);
    }

    // -------------------------------------------------------------------------
    // getPapers
    // -------------------------------------------------------------------------

    public function testGetPapersRequiresAuthentication(): void
    {
        $this->getJson('/user/papers')->assertStatus(401);
    }

    public function testGetPapersReturnsEmptyWhenNoPaper(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/user/papers');

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data.items'));
    }

    public function testGetPapersReturnsExistingItems(): void
    {
        $paper = UserPaper::factory()->create(['user_id' => $this->user->id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::PortraitImage->value,
            'folder' => null,
            'approved_at' => null,
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/user/papers');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function testGetPapersFiltersByType(): void
    {
        $paper = UserPaper::factory()->create(['user_id' => $this->user->id]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::PortraitImage->value,
            'folder' => null,
            'approved_at' => null,
        ]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::Passport->value,
            'folder' => null,
            'approved_at' => null,
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/user/papers?type=' . UserPaperType::Passport->value);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame(UserPaperType::Passport->value, $response->json('data.items.0.type'));
    }

    public function testGetPapersRejectsInvalidType(): void
    {
        $this->actingAs($this->user, 'api')->getJson('/user/papers?type=invalid_type')->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // uploadPapers
    // -------------------------------------------------------------------------

    public function testUploadPapersRequiresAuthentication(): void
    {
        $this->postJson('/user/papers', [])->assertStatus(401);
    }

    public function testUploadPapersStillAcceptsEveryTypeForLegacyClients(): void
    {
        // The released mobile app still posts step-managed types here; the endpoint
        // must keep the old behavior until the step-flow app release is forced out.
        foreach (UserPaperType::cases() as $paperType) {
            $this->actingAs($this->user, 'api')
                ->postJson('/user/papers', [
                    'type' => $paperType->value,
                    'files' => [UploadedFile::fake()->image('photo.jpg')],
                ])
                ->assertStatus(200);
        }

        $this->assertSame(count(UserPaperType::cases()), UserPaperItem::query()->count());
    }

    public function testUploadPapersSendsApprovedUserBackToReview(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/user/papers', [
                'type' => UserPaperType::Passport->value,
                'files' => [UploadedFile::fake()->image('photo.jpg')],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $this->user->refresh()->paper_status);
    }

    public function testUploadPapersRequiresType(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/user/papers', [
                'files' => [UploadedFile::fake()->image('photo.jpg')],
            ])
            ->assertStatus(422);
    }

    public function testUploadPapersRequiresFiles(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/user/papers', [
                'type' => UserPaperType::PortraitImage->value,
            ])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // updateSettings
    // -------------------------------------------------------------------------

    public function testUpdateSettingsRequiresAuthentication(): void
    {
        $this->postJson('/user/settings', ['lang' => 'en'])->assertStatus(401);
    }

    public function testUpdateSettingsSuccess(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/user/settings', ['lang' => 'ja']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
        ]);
        $this->assertSame('ja', $this->user->fresh()->settings['lang']);
    }

    public function testUpdateSettingsRejectsInvalidLang(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/user/settings', ['lang' => 'fr'])
            ->assertStatus(422);
    }

    public function testUpdateSettingsRequiresLang(): void
    {
        $this->actingAs($this->user, 'api')->postJson('/user/settings', [])->assertStatus(422);
    }

    public function testUpdateSettingsReturnsErrorWhenServiceFails(): void
    {
        $this->mock(UserService::class, function ($mock) {
            $mock
                ->shouldReceive('updateSettings')
                ->once()
                ->andReturn(['error' => ErrorCode::BadRequest, 'status' => 400, 'data' => null]);
        });

        $this->actingAs($this->user, 'api')
            ->postJson('/user/settings', ['lang' => 'en'])
            ->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // updatePassword
    // -------------------------------------------------------------------------

    public function testUpdatePasswordRequiresAuthentication(): void
    {
        $this->postJson('/user/password', [])->assertStatus(401);
    }

    public function testUpdatePasswordRequiresApprovedPaperStatus(): void
    {
        $unapproved = $this->createUser([
            'paper_status' => UserPaperStatus::Registered->value,
            'password' => Hash::make('oldpassword'),
        ]);
        $this->attachUserRole($unapproved);

        $this->actingAs($unapproved, 'api')
            ->postJson('/user/password', [
                'current_password' => 'oldpassword',
                'password' => 'newpassword',
                'password_confirmation' => 'newpassword',
            ])
            ->assertStatus(403);
    }

    public function testUpdatePasswordSuccess(): void
    {
        $this->user->update(['password' => Hash::make('oldpassword123')]);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/password', [
            'current_password' => 'oldpassword123',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('newpassword123', $this->user->fresh()->password));
    }

    public function testUpdatePasswordFailsWithWrongCurrentPassword(): void
    {
        $this->user->update(['password' => Hash::make('correctpassword')]);

        $this->actingAs($this->user, 'api')
            ->postJson('/user/password', [
                'current_password' => 'wrongpassword',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertStatus(422);
    }

    public function testUpdatePasswordFailsWhenConfirmationMismatch(): void
    {
        $this->user->update(['password' => Hash::make('oldpassword123')]);

        $this->actingAs($this->user, 'api')
            ->postJson('/user/password', [
                'current_password' => 'oldpassword123',
                'password' => 'newpassword123',
                'password_confirmation' => 'differentpassword',
            ])
            ->assertStatus(422);
    }

    public function testUpdatePasswordFailsWhenTooShort(): void
    {
        $this->user->update(['password' => Hash::make('oldpassword123')]);

        $this->actingAs($this->user, 'api')
            ->postJson('/user/password', [
                'current_password' => 'oldpassword123',
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // updateUserInfo
    // -------------------------------------------------------------------------

    public function testUpdateUserInfoRequiresAuthentication(): void
    {
        $this->postJson('/user/profile', [])->assertStatus(401);
    }

    public function testUpdateUserInfoSuccess(): void
    {
        $this->user->update(['cross_staff_id' => 'cs-001']);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/profile', [
            'birthday' => '1990-01-01',
            'gender' => Gender::Male->value,
            'nationality' => Nationality::Vietnam->value,
        ]);

        $response->assertStatus(200);
    }

    public function testUpdateUserInfoResponseShowsShortenedNameForSriLankaNationality(): void
    {
        $this->user->update([
            'cross_staff_id' => 'cs-001',
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);

        $response = $this->actingAs($this->user, 'api')->postJson('/user/profile', [
            'birthday' => '1990-01-01',
            'gender' => Gender::Male->value,
            'nationality' => Nationality::SriLanka->value,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    public function testUpdateUserInfoValidationFailsOnMissingRequiredFields(): void
    {
        $this->actingAs($this->user, 'api')->postJson('/user/profile', [])->assertStatus(422);
    }

    public function testUpdateUserInfoValidationFailsOnInvalidGender(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/user/profile', [
                'birthday' => '1990-01-01',
                'gender' => 'invalid_gender',
                'nationality' => Nationality::Vietnam->value,
            ])
            ->assertStatus(422);
    }

    public function testUpdateUserInfoRejectsResidenceNumberHeldByAnotherOwnSlot(): void
    {
        $this->user->update(['cross_staff_id' => 'cs-001']);
        $this->user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_number_2' => 'BB22222222BB',
            'residence_number_3' => 'CC33333333CC',
        ]);

        foreach (['BB22222222BB', 'cc33333333cc'] as $duplicate) {
            $this->actingAs($this->user, 'api')
                ->postJson('/user/profile', [...$this->profilePayload(), 'residence_number_1' => $duplicate])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['residence_number_1'], 'data');
        }

        $this->assertSame('AA11111111AA', $this->user->info->refresh()->residence_number_1);
    }

    public function testUpdateUserInfoAcceptsResidenceNumberNotHeldByOtherSlots(): void
    {
        $this->user->update(['cross_staff_id' => 'cs-001']);
        $this->user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_number_2' => 'BB22222222BB',
            'residence_number_3' => null,
        ]);

        $this->actingAs($this->user, 'api')
            ->postJson('/user/profile', [...$this->profilePayload(), 'residence_number_1' => 'DD44444444DD'])
            ->assertStatus(200);

        $this->assertSame('DD44444444DD', $this->user->info->refresh()->residence_number_1);
    }

    public function testUpdateUserInfoStillSavesWhenUnchangedNumberHasLegacyCopy(): void
    {
        $this->user->update(['cross_staff_id' => 'cs-001']);
        $this->user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_number_2' => 'AA11111111AA',
            'residence_number_3' => null,
        ]);

        $this->actingAs($this->user, 'api')
            ->postJson('/user/profile', [
                ...$this->profilePayload(),
                'residence_number_1' => 'AA11111111AA',
                'address' => 'Tokyo Shinjuku 2-8-1',
            ])
            ->assertStatus(200);

        $this->assertSame('Tokyo Shinjuku 2-8-1', $this->user->info->refresh()->address);
    }

    // -------------------------------------------------------------------------
    // requestAccountDeletion
    // -------------------------------------------------------------------------

    public function testRequestAccountDeletionRequiresAuthentication(): void
    {
        $this->deleteJson('/user/profile/delete')->assertStatus(401);
    }

    public function testRequestAccountDeletionDisablesAccountAndPrefixesEmail(): void
    {
        $originalEmail = $this->user->email;

        $response = $this->actingAs($this->user, 'api')->deleteJson('/user/profile/delete');

        $response->assertStatus(204);

        $fresh = $this->user->fresh();
        $this->assertTrue($fresh->is_disabled);
        $this->assertNotSame($originalEmail, $fresh->email);
        $this->assertStringStartsWith('del', $fresh->email);
        $this->assertSame($originalEmail, User::originalEmailFromDeleted($fresh->email));
    }

    public function testRequestAccountDeletionRevokesTokens(): void
    {
        $authService = app(AuthService::class);
        $token = $authService->createToken($this->user->id);

        $otherUser = User::factory()->create();
        $otherToken = $authService->createToken($otherUser->id);

        $this->actingAs($this->user, 'api')->deleteJson('/user/profile/delete')->assertStatus(204);

        $this->assertDatabaseMissing('access_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('access_tokens', ['id' => $otherToken->id]);
    }

    private function profilePayload(): array
    {
        return [
            'birthday' => '1990-01-01',
            'gender' => Gender::Male->value,
            'nationality' => Nationality::Vietnam->value,
        ];
    }
}
