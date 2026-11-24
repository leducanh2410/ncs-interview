<?php

namespace Tests\Feature\Admin\MasterData;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataSearchUserApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::create([
            'name' => PermissionName::ManagerAuth->value,
            'display_name' => 'Manager Auth',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->permissions()->attach($permission);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
    }

    public function testSearchUserByQueryFindsPartialMatch()
    {
        $user1 = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'staff_no' => 'EMP001',
        ]);
        $user2 = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'staff_no' => 'EMP002',
        ]);
        $user3 = User::factory()->create([
            'first_name' => 'Bobby',
            'last_name' => 'Brown',
            'email' => 'bobby.brown@example.com',
            'staff_no' => 'EMP003',
        ]);

        $this->workplace->users()->attach([$user1->id, $user2->id, $user3->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' .
                http_build_query([
                    'q' => 'john',
                    'workplace_id' => $this->workplace->id,
                ])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($user1->id, $data[0]['id']);
    }

    public function testSearchUserByQueryFindsStaffNoPartialMatch()
    {
        $user1 = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'staff_no' => 'EMP001',
        ]);
        $user2 = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'staff_no' => 'EMP002',
        ]);

        $this->workplace->users()->attach([$user1->id, $user2->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' .
                http_build_query([
                    'q' => 'EMP',
                    'workplace_id' => $this->workplace->id,
                ])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function testSearchUserByExactStaffNoReturnsSingleUser()
    {
        $user1 = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'staff_no' => 'EMP001',
        ]);
        $user2 = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'staff_no' => 'EMP002',
        ]);

        $this->workplace->users()->attach([$user1->id, $user2->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' .
                http_build_query([
                    'staff_no' => 'EMP001',
                    'workplace_id' => $this->workplace->id,
                ])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($user1->id, $data[0]['id']);
    }

    public function testSearchUserByExactStaffNoIgnoresQueryParameter()
    {
        $user1 = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'staff_no' => 'EMP001',
        ]);
        $user2 = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'staff_no' => 'EMP002',
        ]);

        $this->workplace->users()->attach([$user1->id, $user2->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' .
                http_build_query([
                    'q' => 'jane', // This should be ignored when staff_no is provided
                    'staff_no' => 'EMP001', // Exact match should return user1
                    'workplace_id' => $this->workplace->id,
                ])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($user1->id, $data[0]['id']);
        $this->assertNotEquals($user2->id, $data[0]['id']);
    }

    public function testSearchUserReturnsOnlyWorkplaceUsers()
    {
        $userInWorkplace = User::factory()->create([
            'first_name' => 'In Workplace',
            'staff_no' => 'EMP001',
        ]);
        $userOutOfWorkplace = User::factory()->create([
            'first_name' => 'Out of Workplace',
            'staff_no' => 'EMP002',
        ]);

        $this->workplace->users()->attach($userInWorkplace->id);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' .
                http_build_query([
                    'q' => 'Workplace',
                    'workplace_id' => $this->workplace->id,
                ])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($userInWorkplace->id, $data[0]['id']);
    }

    public function testSearchUserRequiresMinimumQueryLengthWithoutStaffNo()
    {
        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' .
                http_build_query([
                    'q' => 'ab', // Less than 3 characters
                    'workplace_id' => $this->workplace->id,
                ])
        );

        $response->assertStatus(422);
    }

    public function testSearchUserAllowsShortQueryWithStaffNoExactMatch()
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'staff_no' => 'AB', // Short staff_no
        ]);

        $this->workplace->users()->attach($user->id);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' .
                http_build_query([
                    'staff_no' => 'AB',
                    'workplace_id' => $this->workplace->id,
                ])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($user->id, $data[0]['id']);
    }

    public function testSearchUserFiltersByPermission()
    {
        $chatAssignPermission = Permission::create([
            'name' => PermissionName::ChatAssign->value,
            'display_name' => 'Chat Assign',
        ]);

        $userWithPermission = User::factory()->create([
            'first_name' => 'Support',
            'last_name' => 'Admin',
            'email' => 'support.admin@example.com',
            'staff_no' => 'ADM001',
        ]);
        $userWithPermission->permissions()->attach($chatAssignPermission);

        User::factory()->create([
            'first_name' => 'Support',
            'last_name' => 'Employee',
            'email' => 'support.employee@example.com',
            'staff_no' => 'EMP001',
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' .
                http_build_query([
                    'q' => 'Support',
                    'permission' => PermissionName::ChatAssign->value,
                ])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($userWithPermission->id, $data[0]['id']);
    }

    public function testSearchUserFiltersByAllPermissions()
    {
        $chatSupportPermission = Permission::query()->firstOrCreate(
            [
                'name' => PermissionName::ChatSupport->value,
            ],
            [
                'display_name' => 'Chat Support',
            ]
        );
        $chatViewPermission = Permission::query()->firstOrCreate(
            [
                'name' => PermissionName::ChatView->value,
            ],
            [
                'display_name' => 'Chat View',
            ]
        );

        $userWithBothPermissions = User::factory()->create([
            'first_name' => 'Support',
            'last_name' => 'Both',
            'email' => 'support.both@example.com',
            'staff_no' => 'ADM001',
        ]);
        $userWithBothPermissions->permissions()->attach([$chatSupportPermission->id, $chatViewPermission->id]);

        $supportOnlyUser = User::factory()->create([
            'first_name' => 'Support',
            'last_name' => 'Only',
            'email' => 'support.only@example.com',
            'staff_no' => 'ADM002',
        ]);
        $supportOnlyUser->permissions()->attach($chatSupportPermission);

        $viewOnlyUser = User::factory()->create([
            'first_name' => 'Support',
            'last_name' => 'Viewer',
            'email' => 'support.viewer@example.com',
            'staff_no' => 'ADM003',
        ]);
        $viewOnlyUser->permissions()->attach($chatViewPermission);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' .
                http_build_query([
                    'q' => 'Support',
                    'permission' => [PermissionName::ChatSupport->value, PermissionName::ChatView->value],
                ])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($userWithBothPermissions->id, $data[0]['id']);
    }

    public function testSearchUserWithoutPermissionFilterReturnsAllUsers()
    {
        $chatAssignPermission = Permission::create([
            'name' => PermissionName::ChatAssign->value,
            'display_name' => 'Chat Assign',
        ]);

        $adminUser = User::factory()->create([
            'first_name' => 'Support',
            'last_name' => 'Admin',
            'staff_no' => 'ADM001',
        ]);
        $adminUser->permissions()->attach($chatAssignPermission);

        User::factory()->create([
            'first_name' => 'Support',
            'last_name' => 'Employee',
            'staff_no' => 'EMP001',
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' . http_build_query(['q' => 'Support'])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function testSearchUserExcludesDisabledUsers()
    {
        $activeUser = User::factory()->create([
            'first_name' => 'Active',
            'staff_no' => 'EMP001',
        ]);
        $disabledUser = User::factory()->create([
            'first_name' => 'Disabled',
            'staff_no' => 'EMP002',
            'is_disabled' => true,
        ]);

        $this->workplace->users()->attach([$activeUser->id, $disabledUser->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' .
                http_build_query([
                    'q' => 'EMP',
                    'workplace_id' => $this->workplace->id,
                ])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($activeUser->id, $data[0]['id']);
    }

    /**
     * The pickers look a value up by id only to resolve the label of something
     * the user already picked and saved. Hiding a disabled user there would show
     * a raw id in the input, so this branch deliberately ignores is_disabled.
     */
    public function testSearchUserByKeyStillResolvesDisabledUsers()
    {
        $disabledUser = User::factory()->create([
            'first_name' => 'Disabled',
            'staff_no' => 'EMP002',
            'is_disabled' => true,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' . http_build_query(['key' => $disabledUser->id])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($disabledUser->id, $data[0]['id']);
    }

    public function testSearchUserByKeyListResolvesDisabledUsersAlongsideActiveOnes()
    {
        $activeUser = User::factory()->create(['first_name' => 'Active', 'staff_no' => 'EMP001']);
        $disabledUser = User::factory()->create([
            'first_name' => 'Disabled',
            'staff_no' => 'EMP002',
            'is_disabled' => true,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' . http_build_query(['key' => [$activeUser->id, $disabledUser->id]])
        );

        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$activeUser->id, $disabledUser->id], $ids);
    }

    /**
     * The counterpart of the lookup above: picking a *new* user must not offer a
     * disabled one, so the exact-staff-no branch keeps the filter.
     */
    public function testSearchUserByExactStaffNoExcludesDisabledUsers()
    {
        User::factory()->create([
            'first_name' => 'Disabled',
            'staff_no' => 'EMP002',
            'is_disabled' => true,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/master/users/search?' . http_build_query(['staff_no' => 'EMP002'])
        );

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }
}
