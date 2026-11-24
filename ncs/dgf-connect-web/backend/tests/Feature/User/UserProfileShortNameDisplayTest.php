<?php

namespace Tests\Feature\User;

use App\Enums\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileShortNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function testOwnProfileShowsShortenedNameForSriLankaNationality(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        $this->actingAs($user, 'api')
            ->getJson('/user/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    public function testOwnProfileKeepsFullNameForNonSriLankaNationality(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
        ]);
        $user->info->update(['nationality' => Nationality::Vietnam->value]);

        $this->actingAs($user, 'api')
            ->getJson('/user/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'YAMADA TARO');
    }
}
