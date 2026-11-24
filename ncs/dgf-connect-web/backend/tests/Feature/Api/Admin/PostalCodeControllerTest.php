<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsJapanPostalCodes;
use Tests\TestCase;

class PostalCodeControllerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsJapanPostalCodes;

    public function testResolveForbiddenWithoutPermission(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin, 'api')
            ->getJson('/admin/postal-codes/resolve?address=' . urlencode('埼玉県坂戸市三光町34-6'))
            ->assertStatus(403);
    }

    public function testLookupForbiddenWithoutPermission(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin, 'api')->getJson('/admin/postal-codes/3500235')->assertStatus(403);
    }

    public function testEmployeeCreationPermissionAloneIsEnoughForBothLookups(): void
    {
        $this->seedJapanPostalCodes();
        $admin = $this->createAdminWithPermission(PermissionName::UserCreateEmployee);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/postal-codes/resolve?address=' . urlencode('埼玉県坂戸市三光町34-6'))
            ->assertStatus(200)
            ->assertJsonPath('data.postal_code', '3500235');
        $this->actingAs($admin, 'api')
            ->getJson('/admin/postal-codes/3500235')
            ->assertStatus(200)
            ->assertJsonPath('data.postal_code', '3500235');
    }

    public function testResolveRequiresAnAddress(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);

        $this->actingAs($admin, 'api')->getJson('/admin/postal-codes/resolve')->assertStatus(422);
        $this->actingAs($admin, 'api')
            ->getJson('/admin/postal-codes/resolve?address=' . str_repeat('a', 256))
            ->assertStatus(422);
    }

    public function testResolveReturnsTheResolution(): void
    {
        $this->seedJapanPostalCodes();
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/admin/postal-codes/resolve?address=' . urlencode('埼玉県坂戸市三光町３４番地６ コピ坂戸１１０号室')
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.postal_code', '3500235');
        $response->assertJsonPath('data.confidence', 'exact');
        $response->assertJsonPath('data.matched.city', '坂戸市');
        $response->assertJsonPath('data.matched.town', '三光町');
        $response->assertJsonPath('data.candidates.0.postal_code', '3500235');
    }

    public function testResolveUsesThePrefectureParameterAsAHint(): void
    {
        $this->seedJapanPostalCodes();
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/admin/postal-codes/resolve?' .
                http_build_query(['address' => '坂戸市三光町34-6', 'prefecture' => '埼玉県'])
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.postal_code', '3500235');
    }

    public function testResolveReportsCandidatesWhenAmbiguous(): void
    {
        $this->seedJapanPostalCodes();
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/admin/postal-codes/resolve?address=' . urlencode('大阪府大阪市北区梅田')
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.postal_code', null);
        $response->assertJsonPath('data.confidence', 'ambiguous');
        $response->assertJsonCount(2, 'data.candidates');
    }

    public function testLookupRejectsMalformedCodes(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);

        $this->actingAs($admin, 'api')->getJson('/admin/postal-codes/12345')->assertStatus(422);
    }

    public function testLookupReturnsEveryAreaOfTheCode(): void
    {
        $this->seedJapanPostalCodes();
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/postal-codes/350-0235');

        $response->assertStatus(200);
        $response->assertJsonPath('data.postal_code', '3500235');
        $response->assertJsonPath('data.areas', [
            [
                'postal_code' => '3500235',
                'prefecture' => '埼玉県',
                'city' => '坂戸市',
                'town' => '三光町',
                'town_detail' => null,
            ],
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/postal-codes/0000000')
            ->assertStatus(200)
            ->assertJsonPath('data.areas', []);
    }

    private function createAdminWithPermission(PermissionName $permissionName): User
    {
        $admin = User::factory()->create();
        $permission = Permission::create([
            'name' => $permissionName->value,
            'display_name' => $permissionName->value,
        ]);
        $admin->permissions()->attach($permission);

        return $admin;
    }
}
