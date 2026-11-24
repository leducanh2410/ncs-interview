<?php

namespace Tests\Feature\Api\User;

use App\Enums\UserPaperStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\TestCase;

class UpdateSettingsTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;

    public function testUpdateSettingsAcceptsNepaliLocale(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value]);
        $this->attachUserRole($user);

        $response = $this->actingAs($user, 'api')->postJson('/user/settings', ['lang' => 'ne']);

        $response->assertStatus(200);
        $this->assertSame('ne', $user->fresh()->settings['lang']);
    }

    public function testUpdateSettingsAcceptsSinhalaLocale(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value]);
        $this->attachUserRole($user);

        $response = $this->actingAs($user, 'api')->postJson('/user/settings', ['lang' => 'si']);

        $response->assertStatus(200);
        $this->assertSame('si', $user->fresh()->settings['lang']);
    }

    public function testUpdateSettingsRejectsUnsupportedLocale(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value]);
        $this->attachUserRole($user);

        $response = $this->actingAs($user, 'api')->postJson('/user/settings', ['lang' => 'fr']);

        $response->assertStatus(422);
    }

    public function testUserWhosePapersAreNotApprovedCanChangeLanguage(): void
    {
        foreach (
            [
                UserPaperStatus::Registered,
                UserPaperStatus::Pending,
                UserPaperStatus::WaitForApproval,
                UserPaperStatus::Rejected,
            ]
            as $status
        ) {
            $user = $this->createUser(['paper_status' => $status->value]);
            $this->attachUserRole($user);

            $response = $this->actingAs($user, 'api')->postJson('/user/settings', ['lang' => 'en']);

            $response->assertStatus(200);
            $this->assertSame('en', $user->fresh()->settings['lang'], $status->value);
        }
    }
}
