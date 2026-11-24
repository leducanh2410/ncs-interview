<?php

namespace Tests\Unit\Migrations;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConvertPassportExpiryDateToDateTest extends TestCase
{
    use RefreshDatabase;

    /** @var int[] */
    private array $createdUserIds = [];

    /**
     * MySQL commits implicitly on DDL, so RefreshDatabase's transaction can undo neither the
     * column change nor the rows written around it. Both are put back by hand, or whatever
     * test runs next in this process inherits a text column and a few stray users.
     */
    protected function tearDown(): void
    {
        if (Schema::getColumnType('user_infos', 'passport_expiry_date') !== 'date') {
            $this->migration()->up();
        }

        User::query()->whereIn('id', $this->createdUserIds)->delete();
        $this->createdUserIds = [];

        parent::tearDown();
    }

    /**
     * Rolls the column back to the text type it had before this migration, writes the
     * legacy values only a VARCHAR column could hold, and reapplies the real up() — the
     * conversion is only worth anything if it survives the rows already in production.
     */
    public function testUpNormalisesLegacyValuesAndConvertsTheColumn(): void
    {
        $alreadyNormalised = $this->createUser();
        $slashFormat = $this->createUser();
        $withTime = $this->createUser();
        $blank = $this->createUser();
        $garbage = $this->createUser();
        $zeroDate = $this->createUser();
        $negativeYear = $this->createUser();
        $unset = $this->createUser();

        $migration = $this->migration();
        $migration->down();

        $this->setRawExpiry($alreadyNormalised, '2026-09-30');
        $this->setRawExpiry($slashFormat, '2027/01/05');
        $this->setRawExpiry($withTime, '2028-03-01 00:00:00');
        $this->setRawExpiry($blank, '');
        $this->setRawExpiry($garbage, 'not a date');
        $this->setRawExpiry($zeroDate, '0000-00-00');
        $this->setRawExpiry($negativeYear, '-0001-11-30');
        $this->setRawExpiry($unset, null);

        $migration->up();

        $this->assertSame('2026-09-30', $this->expiryOf($alreadyNormalised));
        $this->assertSame('2027-01-05', $this->expiryOf($slashFormat));
        $this->assertSame('2028-03-01', $this->expiryOf($withTime));
        $this->assertNull($this->expiryOf($blank));
        $this->assertNull($this->expiryOf($garbage));
        // Both parse without complaint (`0000-00-00` becomes `-0001-11-30`, which parses
        // again), but neither is inside the range a DATE column accepts, and MySQL in strict
        // mode would refuse the ALTER over a single such row.
        $this->assertNull($this->expiryOf($zeroDate));
        $this->assertNull($this->expiryOf($negativeYear));
        // An unset passport stays null rather than becoming the day the migration ran, which
        // is what `Carbon::parse()` returns for an empty value left to the lenient path.
        $this->assertNull($this->expiryOf($unset));
        $this->assertSame('date', Schema::getColumnType('user_infos', 'passport_expiry_date'));
    }

    /**
     * The converted column has to answer the reminder's `Y-m-d` probe with an equality
     * match — the whole point of the change — and equally not match a neighbouring day.
     */
    public function testConvertedColumnMatchesAPlainDateStringComparison(): void
    {
        $user = $this->createUser();
        $user->info()->update(['passport_expiry_date' => '2026-09-30']);

        $this->assertTrue(
            DB::table('user_infos')->where('user_id', $user->id)->where('passport_expiry_date', '2026-09-30')->exists()
        );
        $this->assertFalse(
            DB::table('user_infos')->where('user_id', $user->id)->where('passport_expiry_date', '2026-10-01')->exists()
        );
    }

    public function testDownRestoresTheStringColumn(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertSame('varchar', Schema::getColumnType('user_infos', 'passport_expiry_date'));
    }

    private function createUser(): User
    {
        $user = User::factory()->create();
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function setRawExpiry(User $user, ?string $value): void
    {
        DB::table('user_infos')
            ->where('user_id', $user->id)
            ->update(['passport_expiry_date' => $value]);
    }

    private function expiryOf(User $user): ?string
    {
        return DB::table('user_infos')->where('user_id', $user->id)->value('passport_expiry_date');
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_16_000001_convert_passport_expiry_date_to_date.php');
    }
}
