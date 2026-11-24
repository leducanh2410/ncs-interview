<?php

namespace Tests\Feature\Manage\Announcement;

use App\Enums\AnnouncementState;
use App\Enums\PermissionName;
use App\Models\Announcement;
use App\Models\Permission;
use App\Models\User;
use App\Models\Workplace;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AnnouncementLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-10-02 12:00:00');

        $this->admin = User::factory()->create();
        $this->grantAnnouncementPermissions($this->admin);

        $this->workplaceA = Workplace::factory()->create(['name' => 'Workplace A']);
        $this->workplaceB = Workplace::factory()->create(['name' => 'Workplace B']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testEveryEndpointIsForbiddenWithoutPermission(): void
    {
        $outsider = User::factory()->create();
        $announcement = Announcement::factory()->create();

        $this->actingAs($outsider, 'api')->getJson('/manage/announcements')->assertForbidden();
        $this->actingAs($outsider, 'api')
            ->withHeader('Accept', 'application/json')
            ->post('/manage/announcements', $this->validPayload())
            ->assertForbidden();
        $this->actingAs($outsider, 'api')
            ->withHeader('Accept', 'application/json')
            ->post("/manage/announcements/{$announcement->id}", $this->validPayload(['_method' => 'PUT']))
            ->assertForbidden();
        $this->actingAs($outsider, 'api')
            ->deleteJson("/manage/announcements/{$announcement->id}")
            ->assertForbidden();
    }

    public function testCreateRejectsEndBeforeStartMissingWorkplaceAndBadImage(): void
    {
        $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post('/manage/announcements', $this->validPayload(['end_at' => '2026-10-01 08:00:00']))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['end_at']]);

        $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post('/manage/announcements', $this->validPayload(['workplace_ids' => []]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['workplace_ids']]);

        $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post(
                '/manage/announcements',
                $this->validPayload(['image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')])
            )
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['image']]);

        $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post('/manage/announcements', $this->validPayload(['title_ja' => 'タイトル']))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['content_ja']]);

        $this->assertSame(0, Announcement::query()->count());
    }

    public function testFullLifecycleThroughRealEndpoints(): void
    {
        $createResponse = $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post(
                '/manage/announcements',
                $this->validPayload([
                    'image' => UploadedFile::fake()->image('poster.jpg', 1600, 900),
                    'title_ja' => 'メンテナンス',
                    'content_ja' => '<p>ライン A</p>',
                ])
            );

        $createResponse->assertOk();
        $announcementId = $createResponse->json('data.id');
        $createResponse
            ->assertJsonPath('data.state', AnnouncementState::Active->value)
            ->assertJsonPath('data.workplaces.0.id', $this->workplaceA->id)
            ->assertJsonPath('data.translations.0.lang', 'ja');

        $announcement = Announcement::query()->findOrFail($announcementId);
        $this->assertSame($this->admin->id, $announcement->created_by);
        $this->assertStringStartsWith("announcements/{$announcementId}/", $announcement->image_path);
        Storage::disk('local')->assertExists($announcement->image_path);
        $this->assertNotNull($createResponse->json('data.image_url'));
        $firstImagePath = $announcement->image_path;

        $replaceResponse = $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post(
                "/manage/announcements/{$announcementId}",
                $this->validPayload([
                    '_method' => 'PUT',
                    'title' => 'Updated title',
                    'workplace_ids' => [$this->workplaceA->id, $this->workplaceB->id],
                    'image' => UploadedFile::fake()->image('poster-2.png', 800, 600),
                    'title_ja' => '',
                    'content_ja' => '',
                    'title_en' => 'Maintenance',
                    'content_en' => '<p>Line A</p>',
                ])
            );

        $replaceResponse->assertOk()->assertJsonPath('data.title', 'Updated title');
        $announcement->refresh();
        $this->assertNotSame($firstImagePath, $announcement->image_path);
        Storage::disk('local')->assertMissing($firstImagePath);
        Storage::disk('local')->assertExists($announcement->image_path);
        $this->assertEqualsCanonicalizing(
            [$this->workplaceA->id, $this->workplaceB->id],
            $announcement->workplaces()->pluck('workplaces.id')->all()
        );
        $this->assertSame(['en'], $announcement->translations()->pluck('lang')->all());
        $secondImagePath = $announcement->image_path;

        $removeResponse = $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post(
                "/manage/announcements/{$announcementId}",
                $this->validPayload(['_method' => 'PUT', 'remove_image' => 1, 'is_active' => 0])
            );

        $removeResponse
            ->assertOk()
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.state', AnnouncementState::Inactive->value);
        $announcement->refresh();
        $this->assertNull($announcement->image_path);
        Storage::disk('local')->assertMissing($secondImagePath);

        $reactivateResponse = $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post(
                "/manage/announcements/{$announcementId}",
                $this->validPayload([
                    '_method' => 'PUT',
                    'image' => UploadedFile::fake()->image('poster-3.jpg'),
                ])
            );
        $reactivateResponse->assertOk();
        $announcement->refresh();
        $thirdImagePath = $announcement->image_path;
        Storage::disk('local')->assertExists($thirdImagePath);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/manage/announcements/{$announcementId}")
            ->assertOk();
        $this->assertSoftDeleted('announcements', ['id' => $announcementId]);
        Storage::disk('local')->assertMissing($thirdImagePath);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/manage/announcements/{$announcementId}")
            ->assertNotFound();
        $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post("/manage/announcements/{$announcementId}", $this->validPayload(['_method' => 'PUT']))
            ->assertNotFound();
        $this->actingAs($this->admin, 'api')
            ->getJson('/manage/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }

    public function testStorageFailureDuringCreateLeavesNoRow(): void
    {
        Image::shouldReceive('fromUpload')->once()->andThrow(new RuntimeException('disk full'));

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);

        try {
            $this->actingAs($this->admin, 'api')
                ->withHeader('Accept', 'application/json')
                ->post(
                    '/manage/announcements',
                    $this->validPayload(['image' => UploadedFile::fake()->image('poster.jpg')])
                );
        } finally {
            $this->assertSame(0, Announcement::query()->count());
            $this->assertSame(0, Announcement::query()->withTrashed()->count());
        }
    }

    public function testCreateRejectsBlankHtmlContent(): void
    {
        $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post('/manage/announcements', $this->validPayload(['content' => '<p></p>']))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['content']]);

        $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post(
                '/manage/announcements',
                $this->validPayload(['title_ja' => 'タイトル', 'content_ja' => '<p>&nbsp;</p>'])
            )
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['content_ja']]);

        $this->assertSame(0, Announcement::query()->count());
    }

    public function testStorageFailureDuringImageReplacementKeepsThePreviousImage(): void
    {
        $announcementId = $this->createViaEndpoint(['image' => UploadedFile::fake()->image('poster.jpg')]);
        $announcement = Announcement::query()->findOrFail($announcementId);
        $firstImagePath = $announcement->image_path;
        Storage::disk('local')->assertExists($firstImagePath);

        Image::shouldReceive('fromUpload')->once()->andThrow(new RuntimeException('disk full'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin, 'api')
                ->withHeader('Accept', 'application/json')
                ->post(
                    "/manage/announcements/{$announcementId}",
                    $this->validPayload([
                        '_method' => 'PUT',
                        'title' => 'Should not persist',
                        'image' => UploadedFile::fake()->image('poster-2.jpg'),
                    ])
                );
            $this->fail('Expected the storage failure to propagate');
        } catch (RuntimeException) {
            $announcement->refresh();
            $this->assertSame($firstImagePath, $announcement->image_path);
            $this->assertSame('Line A maintenance', $announcement->title);
            Storage::disk('local')->assertExists($firstImagePath);
        }
    }

    public function testDeleteClearsImagePathOnTheTrashedRow(): void
    {
        $announcementId = $this->createViaEndpoint(['image' => UploadedFile::fake()->image('poster.jpg')]);
        $imagePath = Announcement::query()->findOrFail($announcementId)->image_path;

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/manage/announcements/{$announcementId}")
            ->assertOk();

        $trashed = Announcement::withTrashed()->findOrFail($announcementId);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertNull($trashed->image_path);
        Storage::disk('local')->assertMissing($imagePath);
    }

    public function testListAcceptsTheAllSentinelAndMatchesTranslatedTitles(): void
    {
        $this->createViaEndpoint([
            'title' => 'Bảo trì',
            'title_ja' => 'メンテナンス',
            'content_ja' => '<p>ライン</p>',
        ]);
        $this->createViaEndpoint(['title' => 'Khác']);

        $all = $this->actingAs($this->admin, 'api')
            ->getJson('/manage/announcements?workplace_id=all&state=all&keyword=')
            ->assertOk();
        $this->assertCount(2, $all->json('data.data'));

        $byTranslation = $this->actingAs($this->admin, 'api')
            ->getJson('/manage/announcements?keyword=' . rawurlencode('メンテナンス'))
            ->assertOk();
        $this->assertSame(['Bảo trì'], collect($byTranslation->json('data.data'))->pluck('title')->all());

        $this->createViaEndpoint(['title' => 'Tỷ lệ 100%']);
        $this->createViaEndpoint(['title' => 'Tỷ lệ 1000']);
        $byLiteralPercent = $this->actingAs($this->admin, 'api')
            ->getJson('/manage/announcements?keyword=' . rawurlencode('100%'))
            ->assertOk();
        $this->assertSame(['Tỷ lệ 100%'], collect($byLiteralPercent->json('data.data'))->pluck('title')->all());
    }

    public function testListFiltersAndOrdersAnnouncements(): void
    {
        $this->createViaEndpoint(['title' => 'Active A', 'workplace_ids' => [$this->workplaceA->id]]);
        $this->createViaEndpoint([
            'title' => 'Scheduled B',
            'workplace_ids' => [$this->workplaceB->id],
            'start_at' => '2026-10-05 08:00:00',
            'end_at' => '2026-10-06 08:00:00',
        ]);
        $this->createViaEndpoint([
            'title' => 'Expired A',
            'workplace_ids' => [$this->workplaceA->id],
            'start_at' => '2026-09-01 08:00:00',
            'end_at' => '2026-09-02 08:00:00',
        ]);
        $this->createViaEndpoint(['title' => 'Inactive A', 'is_active' => 0]);

        $all = $this->actingAs($this->admin, 'api')->getJson('/manage/announcements')->assertOk();
        $this->assertSame(
            ['Scheduled B', 'Inactive A', 'Active A', 'Expired A'],
            collect($all->json('data.data'))->pluck('title')->all()
        );

        $byWorkplace = $this->actingAs($this->admin, 'api')
            ->getJson('/manage/announcements?workplace_id=' . $this->workplaceB->id)
            ->assertOk();
        $this->assertSame(['Scheduled B'], collect($byWorkplace->json('data.data'))->pluck('title')->all());

        foreach (
            [
                AnnouncementState::Active->value => ['Active A'],
                AnnouncementState::Scheduled->value => ['Scheduled B'],
                AnnouncementState::Expired->value => ['Expired A'],
                AnnouncementState::Inactive->value => ['Inactive A'],
            ]
            as $state => $expectedTitles
        ) {
            $response = $this->actingAs($this->admin, 'api')
                ->getJson('/manage/announcements?state=' . $state)
                ->assertOk();
            $this->assertSame($expectedTitles, collect($response->json('data.data'))->pluck('title')->all());
        }

        $byKeyword = $this->actingAs($this->admin, 'api')->getJson('/manage/announcements?keyword=Expired')->assertOk();
        $this->assertSame(['Expired A'], collect($byKeyword->json('data.data'))->pluck('title')->all());
    }

    private function grantAnnouncementPermissions(User $user, ?array $permissionNames = null): void
    {
        $permissionNames ??= [
            PermissionName::AnnouncementList,
            PermissionName::AnnouncementCreate,
            PermissionName::AnnouncementEdit,
            PermissionName::AnnouncementDelete,
        ];
        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName->value],
                ['display_name' => $permissionName->value]
            );
            $user->permissions()->attach($permission);
        }
    }

    private function createViaEndpoint(array $overrides = []): int
    {
        $response = $this->actingAs($this->admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post('/manage/announcements', $this->validPayload($overrides));
        $response->assertOk();

        return (int) $response->json('data.id');
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge(
            [
                'title' => 'Line A maintenance',
                'content' => '<p>From 10:00 the line is closed.</p>',
                'workplace_ids' => [$this->workplaceA->id],
                'start_at' => '2026-10-01 08:00:00',
                'end_at' => '2026-10-03 18:00:00',
                'is_active' => 1,
            ],
            $overrides
        );
    }
}
