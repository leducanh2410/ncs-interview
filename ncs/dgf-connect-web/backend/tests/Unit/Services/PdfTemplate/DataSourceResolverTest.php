<?php

namespace Tests\Unit\Services\PdfTemplate;

use App\Enums\Nationality;
use App\Models\User;
use App\Models\UserInfo;
use App\Services\Admin\PdfTemplate\DataSourceResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataSourceResolverTest extends TestCase
{
    use RefreshDatabase;

    private DataSourceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DataSourceResolver();
    }

    public function testResolvesUserModelField(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
        ]);

        $result = $this->resolver->resolve('user.email', $user);
        $this->assertEquals('test@example.com', $result);
    }

    public function testResolvesUserNameAccessor(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
        ]);
        $user->info->update(['nationality' => Nationality::Vietnam->value]);

        $result = $this->resolver->resolve('user.name', $user);
        // User model normalizes names to uppercase
        $this->assertEquals('YAMADA TARO', $result);
    }

    public function testResolvesUserNameFieldAsShortenedNameForSriLankaNationality(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        // short_name is persisted by UserInfoShortNameObserver via a separate User
        // instance; reload so this $user reflects it, same as a fresh request would.
        $user = $user->fresh();

        $result = $this->resolver->resolve('user.name', $user);
        $this->assertEquals('THARINDU RAVISHANN', $result);
    }

    public function testResolvesUserInfoModelField(): void
    {
        $user = User::factory()->create();
        $user->info()->update([
            'phone' => '090-1234-5678',
            'address' => 'Tokyo-to Shibuya-ku',
        ]);
        $user->load('info');

        $this->assertEquals('090-1234-5678', $this->resolver->resolve('user_info.phone', $user));
        $this->assertEquals('Tokyo-to Shibuya-ku', $this->resolver->resolve('user_info.address', $user));
    }

    public function testReturnsEmptyStringForNullUserInfo(): void
    {
        $user = User::factory()->create();
        // Delete the auto-created UserInfo
        $user->info()->delete();
        $user->unsetRelation('info');

        $result = $this->resolver->resolve('user_info.phone', $user);
        $this->assertEquals('', $result);
    }

    public function testReturnsEmptyStringForNullModelFieldValue(): void
    {
        $user = User::factory()->create(['staff_no' => null]);

        $result = $this->resolver->resolve('user.staff_no', $user);
        $this->assertEquals('', $result);
    }

    public function testResolvesFixedStringValue(): void
    {
        $user = User::factory()->create();

        $result = $this->resolver->resolve('fixed_value', $user);
        $this->assertEquals('', $result);
    }

    public function testResolvesCustomFunctionCurrentDate(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 20));
        $user = User::factory()->create();

        $result = $this->resolver->resolve('current_date_ymd', $user);
        $this->assertEquals('2026/03/20', $result);
    }

    public function testResolvesCustomFunctionIsHouseholdHead(): void
    {
        $user = User::factory()->create();
        $user->info()->update(['is_household_head' => true]);
        $user->load('info');

        $result = $this->resolver->resolve('is_household_head', $user);
        // DB boolean column returns 1/0 without explicit cast
        $this->assertNotEmpty($result);
    }

    public function testResolvesIsHouseholdHeadFalseWithNullUserInfo(): void
    {
        $user = User::factory()->create();
        $user->info()->delete();
        $user->unsetRelation('info');

        $result = $this->resolver->resolve('is_household_head', $user);
        $this->assertFalse($result);
    }

    public function testResolvesBirthDateParts(): void
    {
        $user = User::factory()->create();
        $user->info()->update(['birthday' => '1990-05-15']);
        $user->load('info');

        $this->assertEquals('1990', $this->resolver->resolve('user_info.birth_year', $user));
        $this->assertEquals('05', $this->resolver->resolve('user_info.birth_month', $user));
        $this->assertEquals('15', $this->resolver->resolve('user_info.birth_date', $user));
    }

    public function testResolvesBirthDatePartsWithNullBirthday(): void
    {
        $user = User::factory()->create();
        $user->info()->update(['birthday' => null]);
        $user->load('info');

        $this->assertEquals('', $this->resolver->resolve('user_info.birth_year', $user));
        $this->assertEquals('', $this->resolver->resolve('user_info.birth_month', $user));
        $this->assertEquals('', $this->resolver->resolve('user_info.birth_date', $user));
    }

    public function testResolvesPostalCodeParts(): void
    {
        $user = User::factory()->create();
        $user->info()->update(['postal_code' => '150-0001']);
        $user->load('info');

        $this->assertEquals('150', $this->resolver->resolve('user.postal_code_first_3', $user));
        $this->assertEquals('0001', $this->resolver->resolve('user.postal_code_last_4', $user));
    }

    public function testResolvesFullAddress(): void
    {
        $user = User::factory()->create();
        $user->info()->update([
            'postal_code' => '150-0001',
            'prefecture' => 'Tokyo-to',
            'address' => 'Shibuya-ku 1-2-3',
        ]);
        $user->load('info');

        $result = $this->resolver->resolve('user.full_address', $user);
        $this->assertEquals('150-0001 Tokyo-to Shibuya-ku 1-2-3', $result);
    }

    public function testResolvesFullAddressWithoutPostcode(): void
    {
        $user = User::factory()->create();
        $user->info()->update([
            'postal_code' => '150-0001',
            'prefecture' => 'Tokyo-to',
            'address' => 'Shibuya-ku 1-2-3',
        ]);
        $user->load('info');

        $result = $this->resolver->resolve('user.full_address_without_postcode', $user);
        $this->assertEquals('Tokyo-to Shibuya-ku 1-2-3', $result);
    }

    public function testResolvesFullAddressWithNullUserInfo(): void
    {
        $user = User::factory()->create();
        $user->info()->delete();
        $user->unsetRelation('info');

        $this->assertEquals('', $this->resolver->resolve('user.full_address', $user));
        $this->assertEquals('', $this->resolver->resolve('user.full_address_without_postcode', $user));
    }

    public function testReturnsEmptyStringForUnknownKey(): void
    {
        $user = User::factory()->create();

        $result = $this->resolver->resolve('nonexistent.key', $user);
        $this->assertEquals('', $result);
    }

    public function testResolveAllReturnsBatchResults(): void
    {
        $user = User::factory()->create([
            'email' => 'batch@example.com',
        ]);

        $keys = ['user.email', 'fixed_value', 'nonexistent.key'];
        $results = $this->resolver->resolveAll($keys, $user);

        $this->assertEquals('batch@example.com', $results['user.email']);
        $this->assertEquals('', $results['fixed_value']);
        $this->assertEquals('', $results['nonexistent.key']);
    }

    public function testGetAllReturnsAllSourcesWithoutResolvers(): void
    {
        $sources = $this->resolver->getAll();

        $this->assertIsArray($sources);
        $this->assertNotEmpty($sources);

        $first = $sources[0];
        $this->assertArrayHasKey('key', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('placeholder', $first);
        $this->assertArrayHasKey('type', $first);
        $this->assertArrayHasKey('group', $first);
        $this->assertArrayNotHasKey('resolver', $first);
        $this->assertArrayNotHasKey('value', $first);
    }

    public function testGetAllContainsExpectedSources(): void
    {
        $sources = $this->resolver->getAll();
        $keys = array_column($sources, 'key');

        $this->assertContains('user.email', $keys);
        $this->assertContains('fixed_value', $keys);
        $this->assertContains('current_date_ymd', $keys);
        $this->assertContains('is_household_head', $keys);
        $this->assertContains('is_era_meiji', $keys);
        $this->assertContains('is_era_taisho', $keys);
        $this->assertContains('is_era_showa', $keys);
        $this->assertContains('is_era_heisei', $keys);
        $this->assertContains('is_era_reiwa', $keys);
    }

    public function testResolvesJapaneseEraMeiji(): void
    {
        $user = User::factory()->create();
        $user->info()->update(['birthday' => '1900-05-01']);
        $user->load('info');

        $this->assertTrue($this->resolver->resolve('is_era_meiji', $user));
        $this->assertFalse($this->resolver->resolve('is_era_taisho', $user));
        $this->assertFalse($this->resolver->resolve('is_era_showa', $user));
        $this->assertFalse($this->resolver->resolve('is_era_heisei', $user));
        $this->assertFalse($this->resolver->resolve('is_era_reiwa', $user));
    }

    public function testResolvesJapaneseEraTaisho(): void
    {
        $user = User::factory()->create();
        $user->info()->update(['birthday' => '1920-03-15']);
        $user->load('info');

        $this->assertFalse($this->resolver->resolve('is_era_meiji', $user));
        $this->assertTrue($this->resolver->resolve('is_era_taisho', $user));
    }

    public function testResolvesJapaneseEraShowa(): void
    {
        $user = User::factory()->create();
        $user->info()->update(['birthday' => '1970-06-15']);
        $user->load('info');

        $this->assertFalse($this->resolver->resolve('is_era_taisho', $user));
        $this->assertTrue($this->resolver->resolve('is_era_showa', $user));
        $this->assertFalse($this->resolver->resolve('is_era_heisei', $user));
    }

    public function testResolvesJapaneseEraHeisei(): void
    {
        $user = User::factory()->create();
        $user->info()->update(['birthday' => '1995-01-01']);
        $user->load('info');

        $this->assertFalse($this->resolver->resolve('is_era_showa', $user));
        $this->assertTrue($this->resolver->resolve('is_era_heisei', $user));
        $this->assertFalse($this->resolver->resolve('is_era_reiwa', $user));
    }

    public function testResolvesJapaneseEraReiwa(): void
    {
        $user = User::factory()->create();
        $user->info()->update(['birthday' => '2020-08-01']);
        $user->load('info');

        $this->assertFalse($this->resolver->resolve('is_era_heisei', $user));
        $this->assertTrue($this->resolver->resolve('is_era_reiwa', $user));
    }

    public function testResolvesJapaneseEraReturnsFalseWithNullBirthday(): void
    {
        $user = User::factory()->create();
        $user->info()->update(['birthday' => null]);
        $user->load('info');

        $this->assertFalse($this->resolver->resolve('is_era_meiji', $user));
        $this->assertFalse($this->resolver->resolve('is_era_reiwa', $user));
    }

    public function testResolvesJapaneseEraReturnsFalseWithNullUserInfo(): void
    {
        $user = User::factory()->create();
        $user->info()->delete();
        $user->unsetRelation('info');

        $this->assertFalse($this->resolver->resolve('is_era_reiwa', $user));
    }

    public function testResolvesJapaneseEraBoundaryDates(): void
    {
        $user = User::factory()->create();

        // Last day of Meiji: 1912-07-29
        $user->info()->update(['birthday' => '1912-07-29']);
        $user->load('info');
        $this->assertTrue($this->resolver->resolve('is_era_meiji', $user));

        // First day of Taisho: 1912-07-30
        $user->info()->update(['birthday' => '1912-07-30']);
        $user->load('info');
        $this->assertTrue($this->resolver->resolve('is_era_taisho', $user));

        // Last day of Taisho: 1926-12-24
        $user->info()->update(['birthday' => '1926-12-24']);
        $user->load('info');
        $this->assertTrue($this->resolver->resolve('is_era_taisho', $user));

        // First day of Showa: 1926-12-25
        $user->info()->update(['birthday' => '1926-12-25']);
        $user->load('info');
        $this->assertTrue($this->resolver->resolve('is_era_showa', $user));

        // Last day of Showa: 1989-01-07
        $user->info()->update(['birthday' => '1989-01-07']);
        $user->load('info');
        $this->assertTrue($this->resolver->resolve('is_era_showa', $user));

        // First day of Heisei: 1989-01-08
        $user->info()->update(['birthday' => '1989-01-08']);
        $user->load('info');
        $this->assertTrue($this->resolver->resolve('is_era_heisei', $user));

        // Last day of Heisei: 2019-04-30
        $user->info()->update(['birthday' => '2019-04-30']);
        $user->load('info');
        $this->assertTrue($this->resolver->resolve('is_era_heisei', $user));

        // First day of Reiwa: 2019-05-01
        $user->info()->update(['birthday' => '2019-05-01']);
        $user->load('info');
        $this->assertTrue($this->resolver->resolve('is_era_reiwa', $user));
    }
}
