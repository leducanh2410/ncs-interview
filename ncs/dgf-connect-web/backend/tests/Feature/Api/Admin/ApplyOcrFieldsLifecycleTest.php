<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPaperStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\Concerns\FakesDocumentOcr;
use Tests\Concerns\ResetsUserDocumentInfo;
use Tests\TestCase;

class ApplyOcrFieldsLifecycleTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use FakesDocumentOcr;
    use RefreshDatabase;
    use ResetsUserDocumentInfo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake();
        Mail::fake();
        Http::fake([
            'insMemberExternal*' => Http::response(['InsMemberExternal' => ['ResultSet' => ['Error' => null]]]),
            'updMemberExternal*' => Http::response(['UpdMemberExternal' => ['ResultSet' => ['Error' => null]]]),
            ...$this->documentOcrProviderStub(),
        ]);
    }

    public function testAdminApplyingUserConfirmedCardRepeatedlyOverwritesItsSlot(): void
    {
        $user = $this->makeUser();
        $admin = $this->createAdmin();
        $this->confirmResidenceCardAsUser($user, 'AB12345678CD', '2028-01-31');

        for ($round = 1; $round <= 3; $round++) {
            $ocrResultId = $this->extractResidenceCardAsAdmin($admin, $user);

            $this->actingAs($admin, 'api')
                ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                    'ocr_result_id' => $ocrResultId,
                    'fields' => ['residence_number', 'residence_expiry_date'],
                ])
                ->assertStatus(200);

            $info = $user->refresh()->info;
            $this->assertSame('AB12345678CD', $info->residence_number_1, "round {$round}");
            $this->assertSame('2030-01-01', $info->residence_expiry_date_1, "round {$round}");
            $this->assertSame(ResidenceStatus::StudyAbroad->value, $info->residence_status_1, "round {$round}");
            $this->assertNull($info->residence_number_2, "round {$round} must not open slot 2");
            $this->assertNull($info->residence_expiry_date_2, "round {$round} must not open slot 2");
            $this->assertNull($info->residence_number_3, "round {$round} must not open slot 3");
        }
    }

    public function testAdminApplyingOlderCardReadingDoesNotOverwriteCurrentCard(): void
    {
        $user = $this->makeUser();
        $admin = $this->createAdmin();
        $user->info->update([
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => '2025-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
        ]);
        $this->ocrFieldOverrides = [
            'residence_card' => ['residence_number' => 'ZZ99999999ZZ', 'residence_expiry_date' => '2029-12-31'],
            'residence_card_scan' => ['residence_number' => 'ZZ99999999ZZ', 'residence_expiry_date' => '2029-12-31'],
        ];
        $this->confirmResidenceCardAsUser($user, 'ZZ99999999ZZ', '2029-12-31');
        $this->assertSame('ZZ99999999ZZ', $user->refresh()->info->residence_number_2);

        $this->ocrFieldOverrides = [];
        $ocrResultId = $this->extractResidenceCardAsAdmin($admin, $user);
        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $ocrResultId,
                'fields' => ['residence_number', 'residence_expiry_date'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AB12345678CD', $info->residence_number_1);
        $this->assertSame('2030-01-01', $info->residence_expiry_date_1);
        $this->assertSame('ZZ99999999ZZ', $info->residence_number_2);
        $this->assertSame('2029-12-31', $info->residence_expiry_date_2);
        $this->assertNull($info->residence_number_3);
    }

    private function makeUser(): User
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Pending->value]);
        $this->resetUserDocumentInfo($user);
        $user->paper()->create();

        return $user->fresh(['info', 'paper']);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        foreach ([PermissionName::UserPaperList, PermissionName::UserPaperApprove] as $permissionName) {
            $admin
                ->permissions()
                ->attach(
                    Permission::create(['name' => $permissionName->value, 'display_name' => $permissionName->value])
                );
        }

        return $admin;
    }

    private function confirmResidenceCardAsUser(User $user, string $number, string $expiry): void
    {
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/upload', [
                'files' => [
                    UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front.jpg'),
                    UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back.jpg'),
                    UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan.jpg'),
                ],
            ])
            ->assertStatus(200);
        $step = $this->runQueuedOcrFor(UserPaperStep::query()->firstOrFail());

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/confirm', [
                'ocr_result_id' => $step->ocr_result_id,
                'residence_number' => $number,
                'residence_expiry_date' => $expiry,
                'residence_status' => ResidenceStatus::StudyAbroad->value,
                'first_name' => 'Van A',
                'last_name' => 'Nguyen',
                'birthday' => '1998-04-12',
                'gender' => 'male',
                'nationality' => 'vietnam',
                'address' => 'Tokyo Shinjuku 2-8-1',
            ])
            ->assertStatus(200);
    }

    private function extractResidenceCardAsAdmin(User $admin, User $user): int
    {
        $response = $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr", [
                'types' => [UserPaperType::ForeignerCardFront->value, UserPaperType::ForeignerCardBack->value],
                'force' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'ok');

        return $response->json('data.results.0.ocr_result_id');
    }
}
