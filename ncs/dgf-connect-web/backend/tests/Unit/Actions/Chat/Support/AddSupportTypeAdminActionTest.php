<?php

namespace Tests\Unit\Actions\Chat\Support;

use App\Actions\Chat\Support\AddSupportTypeAdminAction;
use App\Enums\PermissionName;
use App\Enums\SupportType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AddSupportTypeAdminActionTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testAddsSupportTypeAdmin(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ChatSupport, PermissionName::ChatView]);

        $actionResult = app(AddSupportTypeAdminAction::class)(SupportType::Salary->value, $user->id);

        $this->assertDatabaseHas('support_type_admins', [
            'support_type' => SupportType::Salary->value,
            'user_id' => $user->id,
        ]);
        $this->assertSame(201, $actionResult->getStatus());
        $this->assertSame($user->id, $actionResult->getData()['user_id']);
    }

    public function testIdempotentOnDuplicate(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ChatSupport, PermissionName::ChatView]);

        $first = app(AddSupportTypeAdminAction::class)(SupportType::Document->value, $user->id);
        $second = app(AddSupportTypeAdminAction::class)(SupportType::Document->value, $user->id);

        $this->assertSame(201, $first->getStatus());
        $this->assertSame(200, $second->getStatus());
        $this->assertDatabaseCount('support_type_admins', 1);
    }

    public function testReturnsErrorWhenUserLacksChatSupportPermission(): void
    {
        $user = User::factory()->create();

        $result = app(AddSupportTypeAdminAction::class)(SupportType::Salary->value, $user->id);

        $this->assertNotNull($result->getError());
        $this->assertSame(422, $result->getStatus());
        $this->assertDatabaseMissing('support_type_admins', [
            'support_type' => SupportType::Salary->value,
            'user_id' => $user->id,
        ]);
    }

    public function testReturnsErrorWhenUserLacksChatViewPermission(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ChatSupport]);

        $result = app(AddSupportTypeAdminAction::class)(SupportType::Salary->value, $user->id);

        $this->assertNotNull($result->getError());
        $this->assertSame(422, $result->getStatus());
        $this->assertDatabaseMissing('support_type_admins', [
            'support_type' => SupportType::Salary->value,
            'user_id' => $user->id,
        ]);
    }
}
