<?php

namespace Tests\Unit\Actions\User\Profile;

use App\Actions\User\Profile\UpdateUserInfoAction;
use App\Enums\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateUserInfoActionTest extends TestCase
{
    use RefreshDatabase;

    public function testUpdatesInfoAndSyncsShortNameForSriLankaNationality(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
            'cross_staff_id' => 'existing-cross-staff-id',
        ]);
        $this->actingAs($user, 'api');

        $result = app(UpdateUserInfoAction::class)(['nationality' => Nationality::SriLanka->value]);

        $this->assertNull($result->getError());
        $data = $result->getData();
        $this->assertSame('THARINDU RAVISHANN', $data['name']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => 'THARINDU RAVISHANN']);
        $this->assertDatabaseHas('user_infos', [
            'user_id' => $user->id,
            'nationality' => Nationality::SriLanka->value,
        ]);
    }

    public function testUpdatesInfoAndClearsShortNameWhenNationalityIsNotSriLanka(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
            'cross_staff_id' => 'existing-cross-staff-id',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        $this->actingAs($user, 'api');

        $result = app(UpdateUserInfoAction::class)(['nationality' => Nationality::Vietnam->value]);

        $this->assertNull($result->getError());
        $data = $result->getData();
        $this->assertSame('YAMADA TARO', $data['name']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);
    }
}
