<?php

namespace Tests\Feature\Manage\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

class WorkplaceTargetTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        foreach (
            [PermissionName::NotificationList, PermissionName::NotificationCreate, PermissionName::NotificationEdit]
            as $name
        ) {
            $permission = Permission::firstOrCreate(['name' => $name->value], ['display_name' => $name->value]);
            $this->admin->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->workplaceA = $this->createWorkplaceAt(35.0, 139.0, 'Workplace A');
        $this->workplaceB = $this->createWorkplaceAt(35.1, 139.1, 'Workplace B');
    }

    public function testCreateWithSelectedWorkplacesPersistsPivotAndTargetType(): void
    {
        $response = $this->createNotification([
            'type' => NotificationType::NORMAL->value,
            'target_type' => NotificationTargetType::Workplace->value,
            'workplace_ids' => [$this->workplaceA->id, $this->workplaceB->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.target_type', NotificationTargetType::Workplace->value);
        $response->assertJsonCount(2, 'data.workplaces');

        $notification = Notification::findOrFail($response->json('data.id'));
        $this->assertEqualsCanonicalizing(
            [$this->workplaceA->id, $this->workplaceB->id],
            $notification->workplaces()->pluck('workplaces.id')->all()
        );
    }

    public function testBankAccountRequiredTypeAcceptsWorkplaceTargetAndDropsUrl(): void
    {
        $response = $this->createNotification([
            'type' => NotificationType::BANK_ACCOUNT_REQUIRED->value,
            'target_type' => NotificationTargetType::Workplace->value,
            'workplace_ids' => [$this->workplaceA->id],
            'url' => 'https://example.com',
        ]);

        $response->assertOk();
        $notification = Notification::findOrFail($response->json('data.id'));
        $this->assertSame(NotificationType::BANK_ACCOUNT_REQUIRED->value, $notification->type);
        $this->assertNull($notification->url);
        $this->assertSame([$this->workplaceA->id], $notification->workplaces()->pluck('workplaces.id')->all());
    }

    public function testAllTargetStoresNoWorkplaces(): void
    {
        $response = $this->createNotification([
            'type' => NotificationType::NORMAL->value,
            'target_type' => NotificationTargetType::All->value,
            'workplace_ids' => [$this->workplaceA->id],
        ]);

        $response->assertOk();
        $notification = Notification::findOrFail($response->json('data.id'));
        $this->assertSame(NotificationTargetType::All->value, $notification->target_type);
        $this->assertSame(0, $notification->workplaces()->count());
    }

    public function testMissingTargetTypeDefaultsToAll(): void
    {
        $response = $this->createNotification(['type' => NotificationType::NORMAL->value]);

        $response->assertOk();
        $this->assertSame(
            NotificationTargetType::All->value,
            Notification::findOrFail($response->json('data.id'))->target_type
        );
    }

    public function testWorkplaceTargetRequiresAtLeastOneWorkplace(): void
    {
        $this->createNotification([
            'type' => NotificationType::NORMAL->value,
            'target_type' => NotificationTargetType::Workplace->value,
            'workplace_ids' => [],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['workplace_ids']]);
    }

    public function testUnknownWorkplaceIsRejected(): void
    {
        $this->createNotification([
            'type' => NotificationType::NORMAL->value,
            'target_type' => NotificationTargetType::Workplace->value,
            'workplace_ids' => [999999],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['workplace_ids.0']]);
    }

    public function testSystemOnlyTypesCannotBeCreatedByAdmin(): void
    {
        $this->createNotification(['type' => NotificationType::PAPER_APPROVED->value])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['type']]);
    }

    public function testRecruitmentIgnoresWorkplaceTargetFields(): void
    {
        $shift = $this->createShiftAt($this->workplaceA);

        $response = $this->createNotification([
            'type' => NotificationType::RECRUITMENT->value,
            'shift_id' => $shift->id,
            'gender_target' => 'both',
            'requires_experience' => false,
            'include_nearby_workplaces' => false,
            'include_frequent_urgent_group' => false,
            'target_type' => NotificationTargetType::Workplace->value,
            'workplace_ids' => [$this->workplaceB->id],
        ]);

        $response->assertOk();
        $notification = Notification::findOrFail($response->json('data.id'));
        $this->assertSame(NotificationTargetType::All->value, $notification->target_type);
        $this->assertSame(0, $notification->workplaces()->count());
    }

    public function testEditReplacesSelectedWorkplacesWhilePending(): void
    {
        $id = $this->createNotification([
            'type' => NotificationType::NORMAL->value,
            'target_type' => NotificationTargetType::Workplace->value,
            'workplace_ids' => [$this->workplaceA->id],
        ])->json('data.id');

        $this->actingAs($this->admin, 'api')
            ->putJson(
                "/manage/notifications/{$id}",
                $this->payload([
                    'type' => NotificationType::NORMAL->value,
                    'target_type' => NotificationTargetType::Workplace->value,
                    'workplace_ids' => [$this->workplaceB->id],
                ])
            )
            ->assertOk();

        $this->assertSame(
            [$this->workplaceB->id],
            Notification::findOrFail($id)->workplaces()->pluck('workplaces.id')->all()
        );
    }

    public function testListIncludesTargetAndWorkplaces(): void
    {
        $this->createNotification([
            'type' => NotificationType::NORMAL->value,
            'target_type' => NotificationTargetType::Workplace->value,
            'workplace_ids' => [$this->workplaceA->id],
        ])->assertOk();

        $response = $this->actingAs($this->admin, 'api')->getJson('/manage/notifications');

        $response->assertOk();
        $response->assertJsonPath('data.data.0.target_type', NotificationTargetType::Workplace->value);
        $response->assertJsonPath('data.data.0.workplaces.0.id', $this->workplaceA->id);
        $response->assertJsonPath('data.data.0.workplaces.0.name', 'Workplace A');
    }

    private function createNotification(array $overrides): TestResponse
    {
        return $this->actingAs($this->admin, 'api')->postJson('/manage/notifications', $this->payload($overrides));
    }

    private function payload(array $overrides): array
    {
        return array_merge(
            [
                'title' => 'Title',
                'content' => 'Content',
                'icon' => 'money',
                'is_manually_scheduled' => false,
            ],
            $overrides
        );
    }
}
