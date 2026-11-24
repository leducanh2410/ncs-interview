<?php

namespace Tests\Feature\Api\Admin;

use App\Actions\Admin\Translation\TranslateContentAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranslateContentTest extends TestCase
{
    use RefreshDatabase;

    public function testForbiddenWithoutPermission(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload());

        $response->assertStatus(403);
    }

    public function testAllowedWithAnySingleQualifyingPermission(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->providerResponse())]);
        $admin = $this->createAdminWithPermission(PermissionName::NotificationEdit);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload());

        $response->assertStatus(200);
    }

    public function testValidationRejectsBadPayloads(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::ExamEdit);

        $cases = [
            // empty items
            array_merge($this->validPayload(), ['items' => []]),
            // duplicate keys
            array_merge($this->validPayload(), [
                'items' => [['key' => 'title', 'text' => 'a'], ['key' => 'title', 'text' => 'b']],
            ]),
            // key fails the [a-z0-9_] regex
            array_merge($this->validPayload(), ['items' => [['key' => 'Title-1', 'text' => 'a']]]),
            // source locale listed as a target
            array_merge($this->validPayload(), ['target_locales' => ['vi', 'en']]),
            // unknown context
            array_merge($this->validPayload(), ['context' => 'system_prompt']),
            // unknown locale
            array_merge($this->validPayload(), ['source_locale' => 'fr']),
        ];

        foreach ($cases as $index => $payload) {
            $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $payload);
            $this->assertSame(422, $response->status(), "case {$index} should fail validation");
        }
    }

    public function testValidationRejectsOversizedBatch(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::ExamEdit);

        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = ['key' => "item_{$i}", 'text' => str_repeat('あ', 4500)];
        }

        $response = $this->actingAs($admin, 'api')->postJson(
            '/admin/translate',
            array_merge($this->validPayload(), ['items' => $items])
        );

        $response->assertStatus(422);
    }

    public function testTranslatesAndReturnsPerLocaleMap(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->providerResponse())]);
        $admin = $this->createAdminWithPermission(PermissionName::PollCreate);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload());

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.translations.en.title', 'Notice')
            ->assertJsonPath('data.translations.ja.title', 'お知らせ')
            ->assertJsonPath('data.translations.ne.content', 'सामग्री')
            ->assertJsonPath('data.translations.si.content', 'අන්තර්ගතය');

        Http::assertSent(function (Request $request) {
            $body = $request->data();
            $schema = Arr::get($body, 'response_format.json_schema');

            return str_ends_with($request->url(), '/chat/completions') &&
                Arr::get($schema, 'strict') === true &&
                Arr::get($schema, 'schema.required') === ['en', 'ja', 'ne', 'si'] &&
                Arr::get($schema, 'schema.properties.ne.required') === ['title', 'content'] &&
                str_contains(Arr::get($body, 'messages.0.content'), 'Tiêu đề');
        });
    }

    public function testProviderErrorReturns422(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => 'boom'], 500)]);
        $admin = $this->createAdminWithPermission(PermissionName::ConfigEdit);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload());

        $response
            ->assertStatus(422)
            ->assertJsonPath('data.message', TranslateContentAction::ERROR_TRANSLATION_PROVIDER);
    }

    public function testProviderInvalidJsonReturns422(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'not json']]],
            ]),
        ]);
        $admin = $this->createAdminWithPermission(PermissionName::ExamCreate);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload());

        $response
            ->assertStatus(422)
            ->assertJsonPath('data.message', TranslateContentAction::ERROR_TRANSLATION_PROVIDER);
    }

    public function testProviderResponseMissingTargetLocaleReturns422(): void
    {
        $incomplete = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode(['en' => ['title' => 'Notice', 'content' => 'Content']]),
                    ],
                ],
            ],
        ];
        Http::fake(['openrouter.ai/*' => Http::response($incomplete)]);
        $admin = $this->createAdminWithPermission(PermissionName::NotificationCreate);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload());

        $response
            ->assertStatus(422)
            ->assertJsonPath('data.message', TranslateContentAction::ERROR_TRANSLATION_PROVIDER);
    }

    public function testProviderResponseMissingItemReturns422(): void
    {
        $incomplete = $this->providerResponse();
        $translations = json_decode(Arr::get($incomplete, 'choices.0.message.content'), true);
        unset($translations['ja']['content']);
        $incomplete['choices'][0]['message']['content'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
        Http::fake(['openrouter.ai/*' => Http::response($incomplete)]);
        $admin = $this->createAdminWithPermission(PermissionName::NotificationCreate);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload());

        $response
            ->assertStatus(422)
            ->assertJsonPath('data.message', TranslateContentAction::ERROR_TRANSLATION_PROVIDER);
    }

    public function testProviderResponseWithNonStringItemReturns422(): void
    {
        $invalid = $this->providerResponse();
        $translations = json_decode(Arr::get($invalid, 'choices.0.message.content'), true);
        $translations['ne']['content'] = null;
        $invalid['choices'][0]['message']['content'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
        Http::fake(['openrouter.ai/*' => Http::response($invalid)]);
        $admin = $this->createAdminWithPermission(PermissionName::NotificationCreate);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload());

        $response
            ->assertStatus(422)
            ->assertJsonPath('data.message', TranslateContentAction::ERROR_TRANSLATION_PROVIDER);
    }

    public function testProviderResponseWithBlankItemReturns422(): void
    {
        $invalid = $this->providerResponse();
        $translations = json_decode(Arr::get($invalid, 'choices.0.message.content'), true);
        $translations['ja']['content'] = '   ';
        $invalid['choices'][0]['message']['content'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
        Http::fake(['openrouter.ai/*' => Http::response($invalid)]);
        $admin = $this->createAdminWithPermission(PermissionName::NotificationCreate);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload());

        $response
            ->assertStatus(422)
            ->assertJsonPath('data.message', TranslateContentAction::ERROR_TRANSLATION_PROVIDER);
    }

    public function testThrottleRejectsRequestsOverTheLimit(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->providerResponse())]);
        $admin = $this->createAdminWithPermission(PermissionName::NotificationEdit);

        foreach (range(1, 30) as $attempt) {
            $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload())->assertStatus(200);
        }

        $response = $this->actingAs($admin, 'api')->postJson('/admin/translate', $this->validPayload());

        // The app renders TooManyRequestsHttpException as 400 E_TOO_MANY_ATTEMPTS (bootstrap/app.php)
        $response->assertStatus(400)->assertJsonPath('error', ErrorCode::Throttle->value);
    }

    private function validPayload(): array
    {
        return [
            'items' => [['key' => 'title', 'text' => 'Tiêu đề'], ['key' => 'content', 'text' => 'Nội dung thông báo']],
            'source_locale' => 'vi',
            'target_locales' => ['en', 'ja', 'ne', 'si'],
            'context' => 'notification',
        ];
    }

    private function providerResponse(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode(
                            [
                                'en' => ['title' => 'Notice', 'content' => 'Content'],
                                'ja' => ['title' => 'お知らせ', 'content' => '内容'],
                                'ne' => ['title' => 'सूचना', 'content' => 'सामग्री'],
                                'si' => ['title' => 'දැනුම්දීම', 'content' => 'අන්තර්ගතය'],
                            ],
                            JSON_UNESCAPED_UNICODE
                        ),
                    ],
                ],
            ],
            'model' => 'google/gemini-2.5-flash',
        ];
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
