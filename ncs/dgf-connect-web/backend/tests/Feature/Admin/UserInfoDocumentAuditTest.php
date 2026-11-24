<?php

namespace Tests\Feature\Admin;

use App\Enums\PermissionName;
use App\Enums\UserPaperStatus;
use App\Models\Permission;
use App\Models\User;
use App\Observers\UserInfoDocumentAuditObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The audit trail must name the actor and the write path for every change to the
 * document columns — both for a manual admin edit and for the CrossStaff sync,
 * which overwrites residence slot 1 without an admin typing anything.
 */
class UserInfoDocumentAuditTest extends TestCase
{
    use RefreshDatabase;

    private function createAdminWithPermission(PermissionName $permissionName): User
    {
        $admin = User::factory()->create(['paper_status' => UserPaperStatus::Approved]);
        $permission = Permission::create([
            'name' => $permissionName->value,
            'display_name' => $permissionName->value,
        ]);
        $admin->permissions()->attach($permission);

        return $admin;
    }

    private function createTargetUser(): User
    {
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved]);
        $user->info->update([
            'residence_number_1' => null,
            'residence_expiry_date_1' => null,
            'residence_status_1' => null,
            'residence_number_2' => 'UH70860223GA',
            'residence_expiry_date_2' => '2026-12-06',
            'residence_status_2' => 'family_stay',
        ]);

        return $user;
    }

    private function assertAuditLogged(callable $matcher): void
    {
        Log::shouldHaveReceived('info')
            ->withArgs(function (...$args) use ($matcher) {
                return ($args[0] ?? null) === UserInfoDocumentAuditObserver::LOG_MESSAGE && $matcher($args[1] ?? []);
            })
            ->once();
    }

    public function testAdminManualEditIsAuditedWithActorAndSource(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);
        $user = $this->createTargetUser();

        Log::spy();
        $response = $this->actingAs($admin, 'api')->patchJson("/admin/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'first_name_kana' => $user->first_name_kana,
            'last_name_kana' => $user->last_name_kana,
            'email' => $user->email,
            'staff_no' => $user->staff_no ?? '',
            'residence_number_2' => 'UH70860223GA',
            'residence_expiry_date_2' => '2026-06-12',
            'residence_status_2' => 'family_stay',
        ]);

        $response->assertStatus(200);
        $this->assertSame('2026-06-12', $user->refresh()->info->residence_expiry_date_2);
        $this->assertAuditLogged(function (array $context) use ($admin, $user) {
            return $context['user_id'] === $user->id &&
                $context['actor_id'] === $admin->id &&
                $context['actor_email'] === $admin->email &&
                $context['source'] === 'PATCH admin/users/{id}' &&
                $context['changes'] === [
                    'residence_expiry_date_2' => ['old' => '2026-12-06', 'new' => '2026-06-12'],
                ];
        });
    }

    public function testCrossStaffSyncIsAuditedWithSyncRouteAsSource(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserSync);
        $user = $this->createTargetUser();

        Http::fake([
            '*getMemberListExternal*' => Http::response([
                'GetMemberListExternal' => [
                    'ResultSet' => [
                        'Error' => null,
                        'TotalResult' => 1,
                        'Result' => [
                            'LastName' => $user->last_name,
                            'FirstName' => $user->first_name,
                            'LastNameKana' => $user->last_name_kana,
                            'FirstNameKana' => $user->first_name_kana,
                            'PcMail' => $user->email,
                            'CustomAttribute' => [
                                ['NAME' => '在留期限', 'VALUE' => '2026/06/12'],
                                ['NAME' => '在留資格', 'VALUE' => '家族滞在'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        Log::spy();
        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/from-cross-staff");

        $response->assertStatus(200);
        $this->assertAuditLogged(function (array $context) use ($admin, $user) {
            return $context['user_id'] === $user->id &&
                $context['actor_id'] === $admin->id &&
                $context['source'] === 'POST admin/users/{user}/from-cross-staff' &&
                ($context['changes']['residence_expiry_date_1'] ?? null) === [
                    'old' => null,
                    'new' => '2026-06-12',
                ];
        });
    }
}
