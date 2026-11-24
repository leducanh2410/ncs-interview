<?php

namespace Tests\Feature\User;

use App\Enums\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserShortNameObserverTest extends TestCase
{
    use RefreshDatabase;

    public function testNameChangeResyncsShortNameForSriLankaUser(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => 'THARINDU RAVISHANN']);

        // Change only the name; nationality stays Sri Lanka. New full name
        // "KARNASOORIYA MUDIYANSELAGE KASUN NUWAN" has a house marker, so the
        // shortener collapses it to the two given names.
        $user->update(['first_name' => 'Mudiyanselage Kasun Nuwan']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => 'KASUN NUWAN']);
    }

    public function testNameChangeLeavesShortNameNullForNonSriLankaUser(): void
    {
        $user = User::factory()->create(['first_name' => 'Taro', 'last_name' => 'Yamada']);
        $user->info->update(['nationality' => Nationality::Vietnam->value]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);

        $user->update(['first_name' => 'Jiro']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);
    }

    public function testUnrelatedUserUpdateDoesNotTouchShortName(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        // A save that doesn't change the name must leave short_name intact.
        $user->update(['profile_picture' => 'avatar.png']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => 'THARINDU RAVISHANN']);
    }

    public function testNameChangeOnUserWithoutInfoLeavesShortNameNull(): void
    {
        $user = User::factory()->create(['first_name' => 'Taro', 'last_name' => 'Yamada']);
        $user->info->delete();

        $user->update(['first_name' => 'Jiro']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);
    }

    public function testNameChangeAfterInfoDeletedClearsShortNameEvenWithCachedSriLankaRelation(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => 'THARINDU RAVISHANN']);

        // $user->info is now cached in memory holding nationality = SriLanka; deleting through
        // that same relation instance does not clear the cache. If the observer read
        // $user->info?->nationality (the stale cached relation) instead of querying fresh, it
        // would still see SriLanka here and wrongly recompute a short_name from the new name.
        $user->info->delete();

        $user->update(['first_name' => 'Mudiyanselage Kasun Nuwan']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);
    }
}
