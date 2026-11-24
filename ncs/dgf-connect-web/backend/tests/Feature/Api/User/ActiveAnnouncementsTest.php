<?php

namespace Tests\Feature\Api\User;

use App\Enums\PermissionName;
use App\Models\Announcement;
use App\Models\Permission;
use App\Models\User;
use App\Models\Workplace;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActiveAnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    private Workplace $workplaceC;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-10-02 12:00:00');

        $this->workplaceA = Workplace::factory()->create();
        $this->workplaceB = Workplace::factory()->create();
        $this->workplaceC = Workplace::factory()->create();

        $this->employee = User::factory()->create();
        $this->workplaceA->users()->syncWithoutDetaching([$this->employee->id]);
        $this->workplaceB->users()->syncWithoutDetaching([$this->employee->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testReturnsOnlyValidAnnouncementsForTheEmployeeWorkplacesOnceEach(): void
    {
        $both = $this->createAnnouncement('Both A and B', [$this->workplaceA, $this->workplaceB]);
        $onlyA = $this->createAnnouncement('Only A', [$this->workplaceA], ['start_at' => now()->subHours(2)]);
        $this->createAnnouncement('Only C', [$this->workplaceC]);
        $this->createAnnouncement('Inactive', [$this->workplaceA], ['is_active' => false]);
        $this->createAnnouncement(
            'Scheduled',
            [$this->workplaceA],
            [
                'start_at' => now()->addHour(),
                'end_at' => now()->addDay(),
            ]
        );
        $this->createAnnouncement(
            'Expired',
            [$this->workplaceA],
            [
                'start_at' => now()->subDays(3),
                'end_at' => now()->subDay(),
            ]
        );
        $deleted = $this->createAnnouncement('Deleted', [$this->workplaceA]);
        $deleted->delete();

        $response = $this->actingAs($this->employee, 'api')->getJson('/user/announcements/active')->assertOk();

        $this->assertSame([$onlyA->id, $both->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function testWindowBoundariesAreHalfOpen(): void
    {
        $this->createAnnouncement(
            'Starts now',
            [$this->workplaceA],
            ['start_at' => now(), 'end_at' => now()->addHour()]
        );
        $this->createAnnouncement('Ends now', [$this->workplaceA], ['start_at' => now()->subHour(), 'end_at' => now()]);

        $response = $this->actingAs($this->employee, 'api')->getJson('/user/announcements/active')->assertOk();

        $this->assertSame(['Starts now'], collect($response->json('data'))->pluck('title')->all());
    }

    public function testReturnsEmptyListForUsersWithoutWorkplaceMembership(): void
    {
        $this->createAnnouncement('Only A', [$this->workplaceA]);

        $noWorkplace = User::factory()->create();
        $this->actingAs($noWorkplace, 'api')
            ->getJson('/user/announcements/active')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $admin = User::factory()->create();
        $permission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::AnnouncementCreate->value],
            ['display_name' => PermissionName::AnnouncementCreate->value]
        );
        $admin->permissions()->attach($permission);
        $this->actingAs($admin, 'api')
            ->getJson('/user/announcements/active')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function testPayloadShapeIncludesImageUrlAndTranslations(): void
    {
        $admin = User::factory()->create();
        $permission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::AnnouncementCreate->value],
            ['display_name' => PermissionName::AnnouncementCreate->value]
        );
        $admin->permissions()->attach($permission);

        $withoutImageId = $this->actingAs($admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post('/manage/announcements', [
                'title' => 'Plain',
                'content' => '<p>Plain body</p>',
                'workplace_ids' => [$this->workplaceB->id],
                'start_at' => '2026-10-01 08:00:00',
                'end_at' => '2026-10-03 18:00:00',
                'is_active' => 1,
            ])
            ->assertOk()
            ->json('data.id');

        $withImageId = $this->actingAs($admin, 'api')
            ->withHeader('Accept', 'application/json')
            ->post('/manage/announcements', [
                'title' => 'With image',
                'content' => '<p>Body</p>',
                'workplace_ids' => [$this->workplaceA->id],
                'start_at' => '2026-10-02 08:00:00',
                'end_at' => '2026-10-03 18:00:00',
                'is_active' => 1,
                'image' => UploadedFile::fake()->image('poster.jpg', 1400, 700),
                'title_ja' => '画像付き',
                'content_ja' => '<p>本文</p>',
            ])
            ->assertOk()
            ->json('data.id');

        $response = $this->actingAs($this->employee, 'api')->getJson('/user/announcements/active')->assertOk();

        $response
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $withImageId)
            ->assertJsonPath('data.0.title', 'With image')
            ->assertJsonPath('data.0.content', '<p>Body</p>')
            ->assertJsonPath('data.0.start_at', '2026-10-02 08:00:00')
            ->assertJsonPath('data.0.end_at', '2026-10-03 18:00:00')
            ->assertJsonPath('data.0.translations', [
                ['lang' => 'ja', 'title' => '画像付き', 'content' => '<p>本文</p>'],
            ])
            ->assertJsonPath('data.1.id', $withoutImageId)
            ->assertJsonPath('data.1.image_url', null)
            ->assertJsonPath('data.1.translations', []);

        $this->assertIsString($response->json('data.0.image_url'));
        $this->assertSame(
            ['id', 'title', 'content', 'image_url', 'start_at', 'end_at', 'translations'],
            array_keys($response->json('data.0'))
        );
    }

    public function testRequiresAuthentication(): void
    {
        $this->getJson('/user/announcements/active')->assertUnauthorized();
    }

    private function createAnnouncement(string $title, array $workplaces, array $overrides = []): Announcement
    {
        $announcement = Announcement::factory()->create(array_merge(['title' => $title], $overrides));
        $announcement->workplaces()->sync(collect($workplaces)->pluck('id')->all());

        return $announcement;
    }
}
