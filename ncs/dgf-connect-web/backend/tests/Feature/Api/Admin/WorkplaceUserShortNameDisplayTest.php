<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class WorkplaceUserShortNameDisplayTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testWorkplaceUserListShowsShortenedNameForSriLankaNationality(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::WorkplaceUserList]);
        $workplace = Workplace::factory()->create();

        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $user->workplaces()->attach($workplace->id);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/workplaces/{$workplace->id}/users")
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $user->id, 'name' => 'THARINDU RAVISHANN']);
    }
}
