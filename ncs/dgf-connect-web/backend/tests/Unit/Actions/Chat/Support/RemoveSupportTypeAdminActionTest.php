<?php

namespace Tests\Unit\Actions\Chat\Support;

use App\Actions\Chat\Support\RemoveSupportTypeAdminAction;
use App\Enums\SupportType;
use App\Models\SupportTypeAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoveSupportTypeAdminActionTest extends TestCase
{
    use RefreshDatabase;

    public function testRemovesSupportTypeAdmin(): void
    {
        $user = User::factory()->create();
        SupportTypeAdmin::create(['support_type' => SupportType::Shift->value, 'user_id' => $user->id]);

        $this->invoke(SupportType::Shift->value, $user->id);

        $this->assertDatabaseMissing('support_type_admins', [
            'support_type' => SupportType::Shift->value,
            'user_id' => $user->id,
        ]);
    }

    public function testNoopWhenAdminNotAssigned(): void
    {
        $user = User::factory()->create();

        $this->invoke(SupportType::Transportation->value, $user->id);

        $this->assertDatabaseCount('support_type_admins', 0);
    }

    private function invoke(string $type, int $userId): void
    {
        app(RemoveSupportTypeAdminAction::class)($type, $userId);
    }
}
