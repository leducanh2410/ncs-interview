<?php

namespace Tests\Feature\User;

use App\Enums\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserInfoShortNameObserverTest extends TestCase
{
    use RefreshDatabase;

    public function testCreatingUserInfoWithSriLankaNationalityPersistsShortNameAtCreation(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        // Replace the UserFactory-generated info (random nationality) with a fresh
        // row to deterministically exercise the observer's `created` path.
        $user->info->delete();

        $user->info()->create([
            'registered_location' => 'Tokyo',
            'nationality' => Nationality::SriLanka->value,
        ]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => 'THARINDU RAVISHANN']);
    }

    public function testCreatingUserInfoWithNonSriLankaNationalityLeavesShortNameNull(): void
    {
        $user = User::factory()->create(['first_name' => 'Taro', 'last_name' => 'Yamada']);
        $user->info->delete();

        $user->info()->create([
            'registered_location' => 'Tokyo',
            'nationality' => Nationality::Vietnam->value,
        ]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);
    }

    public function testChangingNationalityLaterResyncsShortName(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);

        $user->info->update(['nationality' => Nationality::Vietnam->value]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);

        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => 'THARINDU RAVISHANN']);

        $user->info->update(['nationality' => Nationality::Vietnam->value]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);
    }

    public function testDeletingUserInfoResetsShortNameToNull(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => 'THARINDU RAVISHANN']);

        $user->info->delete();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);
    }
}
