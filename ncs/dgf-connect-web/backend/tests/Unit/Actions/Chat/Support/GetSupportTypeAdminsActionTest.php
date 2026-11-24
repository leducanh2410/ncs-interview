<?php

namespace Tests\Unit\Actions\Chat\Support;

use App\Actions\Chat\Support\GetSupportTypeAdminsAction;
use App\Enums\SupportType;
use App\Models\SupportTypeAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetSupportTypeAdminsActionTest extends TestCase
{
    use RefreshDatabase;

    public function testReturnsEveryTypeEvenWhenEmpty(): void
    {
        $result = $this->invoke();

        $this->assertCount(count(SupportType::cases()), $result);
        $types = array_column($result, 'type');
        foreach (SupportType::values() as $value) {
            $this->assertContains($value, $types);
        }
        // The "Other" type is included alongside the original four.
        $this->assertContains(SupportType::Other->value, $types);
        foreach ($result as $item) {
            $this->assertSame([], $item['admins']);
        }
    }

    public function testGroupsAdminsUnderOtherType(): void
    {
        $user = User::factory()->create();
        SupportTypeAdmin::create(['support_type' => SupportType::Other->value, 'user_id' => $user->id]);

        $result = $this->invoke();

        $otherItem = collect($result)->firstWhere('type', SupportType::Other->value);
        $this->assertCount(1, $otherItem['admins']);
        $this->assertSame($user->id, $otherItem['admins'][0]['user_id']);
    }

    public function testGroupsAdminsByTypeAndIncludesProfilePicture(): void
    {
        $user = User::factory()->create(['profile_picture' => 'https://example.com/avatar.jpg']);
        SupportTypeAdmin::create(['support_type' => SupportType::Shift->value, 'user_id' => $user->id]);

        $result = $this->invoke();

        $shiftItem = collect($result)->firstWhere('type', SupportType::Shift->value);
        $this->assertCount(1, $shiftItem['admins']);
        $this->assertSame($user->id, $shiftItem['admins'][0]['user_id']);
        $this->assertSame('https://example.com/avatar.jpg', $shiftItem['admins'][0]['profile_picture']);

        $salaryItem = collect($result)->firstWhere('type', SupportType::Salary->value);
        $this->assertSame([], $salaryItem['admins']);
    }

    private function invoke(): array
    {
        return app(GetSupportTypeAdminsAction::class)()->getData();
    }
}
