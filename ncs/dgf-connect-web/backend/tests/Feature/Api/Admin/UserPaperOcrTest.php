<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\OcrResultStatus;
use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperType;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsJapanPostalCodes;
use Tests\TestCase;

class UserPaperOcrTest extends TestCase
{
    use RefreshDatabase;
    use SeedsJapanPostalCodes;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
    }

    public function testForbiddenWithoutPermission(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [UserPaperType::Passport->value],
        ]);

        $response->assertStatus(403);
    }

    public function testValidationRejectsEmptyTypes(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [],
        ]);

        $response->assertStatus(422);
    }

    public function testValidationRejectsUnsupportedType(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [UserPaperType::PortraitImage->value],
        ]);

        $response->assertStatus(422);
    }

    public function testRejectsWhenNoUsableItem(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        // Rejected item: not eligible for OCR
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::Passport->value,
            'folder' => UserPaperItemFolder::Rejected->value,
            'approved_at' => null,
            'rejected_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [UserPaperType::Passport->value],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.missing_types', [UserPaperType::Passport->value]);
    }

    public function testPendingItemIsEligibleAndPreferredOverApproved(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $this->createApprovedItem($userPaper, UserPaperType::Passport->value);
        $pending = $this->createPendingItem($userPaper, UserPaperType::Passport->value);

        Http::fake([
            'openrouter.ai/*' => Http::response(
                $this->openRouterResponse([
                    'passport_number' => 'C1234567',
                    'passport_expiry_date' => '2030-06-01',
                ])
            ),
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [UserPaperType::Passport->value],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'ok');
        $response->assertJsonPath('data.results.0.fields.passport_number', 'C1234567');

        $this->assertSame(
            [$pending->id],
            UserPaperOcrResult::query()->firstOrFail()->item_ids,
            'OCR must run on the pending upload, not the older approved item'
        );
    }

    public function testExtractsResidenceCardFromBothSides(): void
    {
        $this->seedJapanPostalCodes();
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $front = $this->createApprovedItem($userPaper, UserPaperType::ForeignerCardFront->value);
        $back = $this->createApprovedItem($userPaper, UserPaperType::ForeignerCardBack->value);

        Http::fake([
            'openrouter.ai/*' => Http::response(
                $this->openRouterResponse([
                    'name' => 'Nguyen  Van A',
                    'last_name_kana' => 'グエン',
                    'first_name_kana' => 'バン ア',
                    'birthday' => '1998-04-12',
                    'gender' => 'male',
                    'nationality' => 'vietnam',
                    'address' => '東京都新宿区西新宿2-8-1',
                    'residence_number' => 'ab1234 5678cd',
                    'residence_expiry_date' => '2027/01/31',
                    'residence_status_raw' => '留学',
                ])
            ),
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [UserPaperType::ForeignerCardFront->value, UserPaperType::ForeignerCardBack->value],
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.results');
        $response->assertJsonPath('data.results.0.group', 'residence_card');
        $response->assertJsonPath('data.results.0.types', [
            UserPaperType::ForeignerCardFront->value,
            UserPaperType::ForeignerCardBack->value,
        ]);
        $response->assertJsonPath('data.results.0.status', 'ok');
        $response->assertJsonPath('data.results.0.fields.name', 'NGUYEN VAN A');
        $response->assertJsonPath('data.results.0.fields.last_name_kana', 'グエン');
        $response->assertJsonPath('data.results.0.fields.first_name_kana', 'バン ア');
        $response->assertJsonPath('data.results.0.fields.prefecture', '東京都');
        // Derived from the address through Japan Post data, never asked of the LLM
        $response->assertJsonPath('data.results.0.fields.postal_code', '1600023');
        $response->assertJsonPath('data.results.0.fields.residence_number', 'AB12345678CD');
        $response->assertJsonPath('data.results.0.fields.residence_expiry_date', '2027-01-31');
        $response->assertJsonPath('data.results.0.fields.residence_status', ResidenceStatus::StudyAbroad->value);
        $response->assertJsonPath('data.results.0.warnings', []);

        Http::assertSent(function (Request $request) {
            $body = $request->data();
            $imageParts = array_filter(
                $body['messages'][0]['content'],
                fn(array $part) => $part['type'] === 'image_url'
            );

            $schemaProperties = $body['response_format']['json_schema']['schema']['properties'] ?? [];
            $prefectureSchema = $schemaProperties['prefecture'] ?? [];

            return str_contains($request->url(), 'chat/completions') &&
                !array_key_exists('postal_code', $schemaProperties) &&
                array_key_exists('last_name_kana', $schemaProperties) &&
                array_key_exists('first_name_kana', $schemaProperties) &&
                count($imageParts) === 2 &&
                $body['response_format']['type'] === 'json_schema' &&
                in_array('東京都', $prefectureSchema['enum'] ?? [], true) &&
                in_array('北海道', $prefectureSchema['enum'] ?? [], true);
        });
    }

    public function testUnresolvedPostalCodeIsLeftEmptyWithAWarning(): void
    {
        $this->seedJapanPostalCodes();
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $this->createApprovedItem($userPaper, UserPaperType::ForeignerCardFront->value);

        Http::fake([
            'openrouter.ai/*' => Http::response(
                $this->openRouterResponse([
                    'name' => 'Nguyen Van A',
                    // The town is split by 丁目 and the address does not say which
                    'address' => '大阪府大阪市北区梅田',
                    'prefecture' => '大阪府',
                ])
            ),
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [UserPaperType::ForeignerCardFront->value],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'ok');
        $response->assertJsonPath('data.results.0.fields.postal_code', null);
        $response->assertJsonPath('data.results.0.warnings', ['postal_code_unresolved']);
    }

    public function testNoPostalCodeWarningWhenTheAddressWasNotRead(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $this->createApprovedItem($userPaper, UserPaperType::ForeignerCardFront->value);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse(['name' => 'Nguyen Van A'])),
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [UserPaperType::ForeignerCardFront->value],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.fields.postal_code', null);
        $response->assertJsonPath('data.results.0.warnings', []);
    }

    public function testIncompleteExtractionStaysUsableAndCachedForAdmin(): void
    {
        // Admins may extract partial image sets (e.g. the back side alone for an address
        // update), so unreadable required fields must not fail the run like they do for
        // the employee flow — the result stays completed, cached, and appliable
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $this->createApprovedItem($userPaper, UserPaperType::ForeignerCardBack->value);

        Http::fake([
            'openrouter.ai/*' => Http::response(
                $this->openRouterResponse([
                    'name' => null,
                    'last_name_kana' => null,
                    'first_name_kana' => null,
                    'birthday' => null,
                    'gender' => null,
                    'nationality' => null,
                    'address' => '東京都新宿区西新宿2-8-1',
                    'residence_number' => null,
                    'residence_expiry_date' => null,
                    'residence_status_raw' => null,
                ])
            ),
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [UserPaperType::ForeignerCardBack->value],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'ok');
        $response->assertJsonPath('data.results.0.fields.address', '東京都新宿区西新宿2-8-1');
        $response->assertJsonPath('data.results.0.fields.prefecture', '東京都');
        $response->assertJsonPath('data.results.0.fields.name', null);
        $this->assertSame(OcrResultStatus::Completed->value, UserPaperOcrResult::query()->firstOrFail()->status);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr", [
                'types' => [UserPaperType::ForeignerCardBack->value],
            ])
            ->assertJsonPath('data.results.0.from_cache', true);
        Http::assertSentCount(1);
    }

    public function testProviderFailureMarksGroupFailedWithoutFailingRequest(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $this->createApprovedItem($userPaper, UserPaperType::ForeignerCardFront->value);
        $this->createApprovedItem($userPaper, UserPaperType::Passport->value);

        // Groups are processed in order: residence_card first, then passport
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->pushStatus(500)
                ->push(
                    $this->openRouterResponse([
                        'passport_number' => 'C1234567',
                        'passport_expiry_date' => '2030-06-01',
                    ])
                ),
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [UserPaperType::ForeignerCardFront->value, UserPaperType::Passport->value],
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.results');
        $response->assertJsonPath('data.results.0.group', 'residence_card');
        $response->assertJsonPath('data.results.0.status', 'failed');
        $response->assertJsonPath('data.results.0.error', 'OCR_PROVIDER_ERROR');
        $response->assertJsonPath('data.results.0.fields', null);
        $response->assertJsonPath('data.results.1.group', 'passport');
        $response->assertJsonPath('data.results.1.status', 'ok');
        $response->assertJsonPath('data.results.1.fields.passport_number', 'C1234567');
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

    public function testSecondRequestIsServedFromCacheWithoutCallingProvider(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $this->createApprovedItem($userPaper, UserPaperType::Passport->value);

        Http::fake([
            'openrouter.ai/*' => Http::response(
                $this->openRouterResponse([
                    'passport_number' => 'C1234567',
                    'passport_expiry_date' => '2030-06-01',
                ])
            ),
        ]);

        $payload = ['types' => [UserPaperType::Passport->value]];

        $first = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", $payload);
        $first->assertStatus(200);
        $first->assertJsonPath('data.results.0.from_cache', false);

        $second = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", $payload);
        $second->assertStatus(200);
        $second->assertJsonPath('data.results.0.from_cache', true);
        $second->assertJsonPath('data.results.0.fields.passport_number', 'C1234567');

        Http::assertSentCount(1);
        $this->assertSame(1, UserPaperOcrResult::query()->count());
    }

    public function testForceBypassesCache(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $this->createApprovedItem($userPaper, UserPaperType::Passport->value);

        Http::fake([
            'openrouter.ai/*' => Http::response(
                $this->openRouterResponse([
                    'passport_number' => 'C1234567',
                    'passport_expiry_date' => '2030-06-01',
                ])
            ),
        ]);

        $payload = ['types' => [UserPaperType::Passport->value]];

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr", $payload)
            ->assertStatus(200);

        $forced = $this->actingAs($admin, 'api')->postJson(
            "/admin/users/{$user->id}/papers/ocr",
            $payload + ['force' => true]
        );
        $forced->assertStatus(200);
        $forced->assertJsonPath('data.results.0.from_cache', false);

        Http::assertSentCount(2);
        $this->assertSame(2, UserPaperOcrResult::query()->count());
    }

    public function testSyncOcrAdvertisesFallbackChainButNeverRetries(): void
    {
        config(['services.openrouter.fallback_model' => 'fallback/model']);

        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $this->createApprovedItem($userPaper, UserPaperType::Passport->value);

        Http::fake(['openrouter.ai/*' => Http::response(null, 429)]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr", [
            'types' => [UserPaperType::Passport->value],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'failed');

        // The synchronous admin path must stay within one provider timeout: the fallback is
        // delegated to OpenRouter's models routing, never an explicit second request
        Http::assertSentCount(1);
        Http::assertSent(
            fn(Request $request) => $request->data()['models'] === ['google/gemini-2.5-flash', 'fallback/model']
        );

        $this->assertSame(OcrResultStatus::Failed->value, UserPaperOcrResult::query()->firstOrFail()->status);
    }

    private function createPendingItem(UserPaper $userPaper, string $type): UserPaperItem
    {
        $path = 'users/' . $userPaper->user_id . '/papers/' . uniqid() . '.png';
        Storage::put($path, $this->makePngBytes());

        return UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => $type,
            'file_path' => $path,
            'folder' => null,
            'approved_at' => null,
            'rejected_at' => null,
            'deleted_at' => null,
        ]);
    }

    private function createApprovedItem(UserPaper $userPaper, string $type): UserPaperItem
    {
        $path = 'users/' . $userPaper->user_id . '/papers/' . uniqid() . '.png';
        Storage::put($path, $this->makePngBytes());

        return UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => $type,
            'file_path' => $path,
            'folder' => UserPaperItemFolder::Current->value,
            'approved_at' => now(),
            'rejected_at' => null,
            'deleted_at' => null,
        ]);
    }

    private function makePngBytes(): string
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    private function openRouterResponse(array $fields): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode($fields),
                    ],
                ],
            ],
        ];
    }
}
