<?php

namespace Tests\Feature\Admin\CompanyHoliday;

use App\Contracts\HolidayProviderContract;
use App\Enums\CompanyHolidaySource;
use App\Enums\PermissionName;
use App\Models\CompanyHoliday;
use App\Models\CompanyHolidaySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\Support\FakeHolidayProvider;
use Tests\TestCase;

class CompanyHolidayTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $manager;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->outsider = User::factory()->create();
        $this->giveGlobalPermission($this->manager, PermissionName::AdminLeaveManage);
        // The outsider is a normal admin: signed in and holding manager.auth, but without the
        // leave permission. That is the realistic shape of an unauthorized caller here.
        $this->giveGlobalPermission($this->outsider, PermissionName::ManagerAuth);

        $this->app->bind(
            HolidayProviderContract::class,
            fn() => new FakeHolidayProvider([
                2027 => [
                    '2027-01-01' => '元日',
                    '2027-01-11' => '成人の日',
                    '2027-02-11' => '建国記念の日',
                ],
            ])
        );
    }

    public function testGenerationPopulatesAYear(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/company-holidays/generate', [
            'year' => 2027,
        ]);

        $response->assertOk();
        $this->assertSame(3, $response->json('data.inserted'));
        $this->assertSame(3, CompanyHoliday::query()->count());
    }

    public function testASecondGenerationInsertsNothingAndPreservesAManualEdit(): void
    {
        $this->actingAs($this->manager, 'api')
            ->postJson('/admin/company-holidays/generate', ['year' => 2027])
            ->assertOk();

        $generated = CompanyHoliday::query()->where('date', '2027-01-01')->firstOrFail();
        $this->actingAs($this->manager, 'api')
            ->putJson('/admin/company-holidays/' . $generated->id, [
                'date' => '2027-01-01',
                'name' => '年始休業（社内）',
            ])
            ->assertOk();

        $response = $this->actingAs($this->manager, 'api')->postJson('/admin/company-holidays/generate', [
            'year' => 2027,
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('data.inserted'));
        $this->assertSame(3, CompanyHoliday::query()->count());
        $this->assertSame('年始休業（社内）', $generated->fresh()->name);
    }

    public function testIndexReturnsTheYearsHolidaysOrderedByDateWithTheWeekendSetting(): void
    {
        $this->createHoliday('2027-05-04', 'みどりの日');
        $this->createHoliday('2027-01-01', '元日');
        // A different year must not leak into the response.
        $this->createHoliday('2026-01-01', '元日');
        CompanyHolidaySetting::query()->create(['year' => 2027, 'exclude_weekends' => true]);

        $response = $this->actingAs($this->manager, 'api')->getJson('/admin/company-holidays?year=2027');

        $response->assertOk();
        $this->assertSame(2027, $response->json('data.year'));
        $this->assertTrue($response->json('data.exclude_weekends'));
        $this->assertSame(['2027-01-01', '2027-05-04'], array_column($response->json('data.holidays'), 'date'));
        $this->assertSame('manual', $response->json('data.holidays.0.source'));
    }

    public function testIndexRejectsAYearOutsideTheAllowedRange(): void
    {
        $this->actingAs($this->manager, 'api')->getJson('/admin/company-holidays?year=1899')->assertStatus(422);
    }

    public function testAManualHolidayIsCreatedUpdatedAndDeleted(): void
    {
        $create = $this->actingAs($this->manager, 'api')->postJson('/admin/company-holidays', [
            'date' => '2027-08-13',
            'name' => '夏季休業',
        ]);

        $create->assertStatus(201);
        $id = $create->json('data.id');
        $this->assertSame(CompanyHolidaySource::Manual->value, $create->json('data.source'));

        $this->actingAs($this->manager, 'api')
            ->putJson('/admin/company-holidays/' . $id, ['date' => '2027-08-14', 'name' => '夏季休業（変更）'])
            ->assertOk();
        $this->assertDatabaseHas('company_holidays', [
            'id' => $id,
            'date' => '2027-08-14',
            'name' => '夏季休業（変更）',
        ]);

        $this->actingAs($this->manager, 'api')
            ->deleteJson('/admin/company-holidays/' . $id)
            ->assertOk();
        $this->assertDatabaseMissing('company_holidays', ['id' => $id]);
    }

    public function testCreatingAHolidayOnAnExistingDateIsRejected(): void
    {
        $this->createHoliday('2027-08-13', '夏季休業');

        $this->actingAs($this->manager, 'api')
            ->postJson('/admin/company-holidays', ['date' => '2027-08-13', 'name' => '重複'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['date']]);
    }

    public function testRenamingAHolidayWithoutMovingItIsNotRejectedAsADuplicateOfItself(): void
    {
        $holiday = $this->createHoliday('2027-08-13', '夏季休業');

        $this->actingAs($this->manager, 'api')
            ->putJson('/admin/company-holidays/' . $holiday->id, ['date' => '2027-08-13', 'name' => '夏季一斉休業'])
            ->assertOk();
    }

    public function testALibrarySourcedHolidayCanBeDeleted(): void
    {
        $holiday = $this->createHoliday('2027-01-01', '元日', CompanyHolidaySource::Library);

        $this->actingAs($this->manager, 'api')
            ->deleteJson('/admin/company-holidays/' . $holiday->id)
            ->assertOk();

        $this->assertDatabaseMissing('company_holidays', ['id' => $holiday->id]);
    }

    public function testTheWeekendSettingIsStoredPerYearAndInheritedByALaterYear(): void
    {
        $this->actingAs($this->manager, 'api')
            ->putJson('/admin/company-holidays/settings', ['year' => 2026, 'exclude_weekends' => true])
            ->assertOk();

        $this->assertDatabaseHas('company_holiday_settings', ['year' => 2026, 'exclude_weekends' => true]);
        // 2027 has no row of its own, so it must report 2026's value rather than a fresh "off".
        $this->assertTrue(
            $this->actingAs($this->manager, 'api')
                ->getJson('/admin/company-holidays?year=2027')
                ->json('data.exclude_weekends')
        );
        // An earlier year keeps whatever it was counted under.
        $this->assertFalse(
            $this->actingAs($this->manager, 'api')
                ->getJson('/admin/company-holidays?year=2025')
                ->json('data.exclude_weekends')
        );
    }

    public function testTogglingTheWeekendSettingTwiceUpdatesTheSameYearRow(): void
    {
        $this->actingAs($this->manager, 'api')
            ->putJson('/admin/company-holidays/settings', ['year' => 2026, 'exclude_weekends' => true])
            ->assertOk();
        $this->actingAs($this->manager, 'api')
            ->putJson('/admin/company-holidays/settings', ['year' => 2026, 'exclude_weekends' => false])
            ->assertOk();

        $this->assertSame(1, CompanyHolidaySetting::query()->where('year', 2026)->count());
        $this->assertFalse(CompanyHolidaySetting::query()->where('year', 2026)->first()->exclude_weekends);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public static function endpointProvider(): array
    {
        return [
            'index' => ['getJson', '/admin/company-holidays?year=2027', []],
            'generate' => ['postJson', '/admin/company-holidays/generate', ['year' => 2027]],
            'store' => ['postJson', '/admin/company-holidays', ['date' => '2027-08-13', 'name' => '夏季休業']],
            'update' => ['putJson', '/admin/company-holidays/{id}', ['date' => '2027-08-13', 'name' => '夏季休業']],
            'destroy' => ['deleteJson', '/admin/company-holidays/{id}', []],
            'settings' => ['putJson', '/admin/company-holidays/settings', ['year' => 2027, 'exclude_weekends' => true]],
        ];
    }

    #[DataProvider('endpointProvider')]
    public function testEveryEndpointIsForbiddenWithoutTheLeavePermission(
        string $method,
        string $url,
        array $payload
    ): void {
        // Route model binding resolves before the permission middleware, so the row the
        // {id}-bearing routes point at has to exist or the test would prove 404, not 403.
        $holiday = $this->createHoliday('2027-01-01', '元日');

        $this->actingAs($this->outsider, 'api')
            ->{$method}(str_replace('{id}', (string) $holiday->id, $url), $payload)
            ->assertForbidden();
    }

    public function testEndpointsRequireAuthentication(): void
    {
        $this->getJson('/admin/company-holidays?year=2027')->assertUnauthorized();
    }

    private function createHoliday(
        string $date,
        string $name,
        CompanyHolidaySource $source = CompanyHolidaySource::Manual
    ): CompanyHoliday {
        return CompanyHoliday::query()->create([
            'date' => $date,
            'name' => $name,
            'source' => $source->value,
        ]);
    }
}
