<?php

namespace Tests\Unit\Services\Csv;

use App\Enums\Gender;
use App\Enums\JapaneseLevel;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;
use App\Models\User;
use App\Services\Csv\CsvPropertyRegistry;
use App\Services\Csv\CsvPropertyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvPropertyResolverTest extends TestCase
{
    use RefreshDatabase;

    private CsvPropertyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CsvPropertyResolver(new CsvPropertyRegistry());
    }

    public function testResolvesUserStringField(): void
    {
        $user = $this->createUserWithInfo(['first_name' => 'Taro']);

        $this->assertSame('TARO', $this->resolver->resolve($user, 'first_name'));
    }

    public function testResolvesUserInfoStringField(): void
    {
        $user = $this->createUserWithInfo([], ['phone' => '090-1234-5678']);

        $this->assertSame('090-1234-5678', $this->resolver->resolve($user, 'phone'));
    }

    public function testResolvesNullUserFieldToEmptyString(): void
    {
        $user = $this->createUserWithInfo(['staff_no' => null]);

        $this->assertSame('', $this->resolver->resolve($user, 'staff_no'));
    }

    public function testResolvesNullUserInfoFieldToEmptyString(): void
    {
        $user = $this->createUserWithInfo([], ['passport_number' => null]);

        $this->assertSame('', $this->resolver->resolve($user, 'passport_number'));
    }

    public function testResolvesEmptyStringToEmptyString(): void
    {
        $user = $this->createUserWithInfo([], ['postal_code' => '']);

        $this->assertSame('', $this->resolver->resolve($user, 'postal_code'));
    }

    public function testResolvesDateField(): void
    {
        $user = $this->createUserWithInfo([], ['birthday' => '2000-06-15']);

        $this->assertSame('="2000/06/15"', $this->resolver->resolve($user, 'birthday'));
    }

    public function testResolvesNullDateToEmptyString(): void
    {
        $user = $this->createUserWithInfo([], ['birthday' => null]);

        $this->assertSame('', $this->resolver->resolve($user, 'birthday'));
    }

    public function testResolvesResidenceExpiryDate(): void
    {
        $user = $this->createUserWithInfo([], ['residence_expiry_date_1' => '2027-03-01']);

        $this->assertSame('="2027/03/01"', $this->resolver->resolve($user, 'residence_expiry_date'));
    }

    public function testResolvesPassportExpiryDate(): void
    {
        $user = $this->createUserWithInfo([], ['passport_expiry_date' => '2030-12-31']);

        $this->assertSame('="2030/12/31"', $this->resolver->resolve($user, 'passport_expiry_date'));
    }

    public function testResolvesStudentCardExpiryDate(): void
    {
        $user = $this->createUserWithInfo([], ['student_card_expiry_date' => '2026-09-30']);

        $this->assertSame('="2026/09/30"', $this->resolver->resolve($user, 'student_card_expiry_date'));
    }

    public function testResolvesBooleanTrue(): void
    {
        $user = $this->createUserWithInfo([], ['is_household_head' => true]);

        $this->assertSame('はい', $this->resolver->resolve($user, 'is_household_head'));
    }

    public function testResolvesBooleanFalse(): void
    {
        $user = $this->createUserWithInfo([], ['is_household_head' => false]);

        $this->assertSame('いいえ', $this->resolver->resolve($user, 'is_household_head'));
    }

    public function testResolvesBooleanWithNoInfoReturnsEmptyString(): void
    {
        $user = User::factory()->create();
        $user->info()->delete();
        $user = $user->fresh();

        $this->assertSame('', $this->resolver->resolve($user, 'is_household_head'));
    }

    public function testResolvesGenderMale(): void
    {
        $user = $this->createUserWithInfo([], ['gender' => Gender::Male->value]);

        $this->assertSame('男性', $this->resolver->resolve($user, 'gender'));
    }

    public function testResolvesGenderFemale(): void
    {
        $user = $this->createUserWithInfo([], ['gender' => Gender::Female->value]);

        $this->assertSame('女性', $this->resolver->resolve($user, 'gender'));
    }

    public function testResolvesGenderOther(): void
    {
        $user = $this->createUserWithInfo([], ['gender' => Gender::Other->value]);

        $this->assertSame('その他', $this->resolver->resolve($user, 'gender'));
    }

    public function testResolvesNationalityVietnam(): void
    {
        $user = $this->createUserWithInfo([], ['nationality' => Nationality::Vietnam->value]);

        $this->assertSame('ベトナム', $this->resolver->resolve($user, 'nationality'));
    }

    public function testResolvesNationalityNepal(): void
    {
        $user = $this->createUserWithInfo([], ['nationality' => Nationality::Nepal->value]);

        $this->assertSame('ネパール', $this->resolver->resolve($user, 'nationality'));
    }

    public function testResolvesNationalityOther(): void
    {
        $user = $this->createUserWithInfo([], ['nationality' => Nationality::Other->value]);

        $this->assertSame('その他', $this->resolver->resolve($user, 'nationality'));
    }

    public function testResolvesJapaneseLevelN1(): void
    {
        $user = $this->createUserWithInfo([], ['japanese_level' => JapaneseLevel::N1->value]);

        $this->assertSame('N1', $this->resolver->resolve($user, 'japanese_level'));
    }

    public function testResolvesJapaneseLevelNone(): void
    {
        $user = $this->createUserWithInfo([], ['japanese_level' => JapaneseLevel::None->value]);

        $this->assertSame('なし', $this->resolver->resolve($user, 'japanese_level'));
    }

    public function testResolvesJapaneseLevelNullToEmptyString(): void
    {
        $user = $this->createUserWithInfo([], ['japanese_level' => null]);

        $this->assertSame('', $this->resolver->resolve($user, 'japanese_level'));
    }

    public function testResolvesResidenceStatusStudyAbroad(): void
    {
        $user = $this->createUserWithInfo([], ['residence_status_1' => ResidenceStatus::StudyAbroad->value]);

        $this->assertSame('留学', $this->resolver->resolve($user, 'residence_status'));
    }

    public function testResolvesLatestResidenceCardByDate(): void
    {
        $user = $this->createUserWithInfo(
            [],
            [
                'residence_number_1' => 'OLD123',
                'residence_expiry_date_1' => '2025-01-01',
                'residence_status_1' => ResidenceStatus::StudyAbroad->value,
                'residence_number_2' => 'NEW456',
                'residence_expiry_date_2' => '2028-06-15',
                'residence_status_2' => ResidenceStatus::PermanentResident->value,
            ]
        );

        $this->assertSame('NEW456', $this->resolver->resolve($user, 'residence_number'));
        $this->assertSame('="2028/06/15"', $this->resolver->resolve($user, 'residence_expiry_date'));
        $this->assertSame('永住者', $this->resolver->resolve($user, 'residence_status'));
    }

    public function testResolvesLatestResidenceCardPrefersHigherIndexOnTie(): void
    {
        $user = $this->createUserWithInfo(
            [],
            [
                'residence_number_1' => 'CARD1',
                'residence_expiry_date_1' => '2027-03-01',
                'residence_status_1' => ResidenceStatus::StudyAbroad->value,
                'residence_number_2' => 'CARD2',
                'residence_expiry_date_2' => '2027-03-01',
                'residence_status_2' => ResidenceStatus::PermanentResident->value,
            ]
        );

        $this->assertSame('CARD2', $this->resolver->resolve($user, 'residence_number'));
        $this->assertSame('永住者', $this->resolver->resolve($user, 'residence_status'));
    }

    public function testResolvesResidenceCardFallsBackToFirstWithDataWhenNoDates(): void
    {
        $user = $this->createUserWithInfo(
            [],
            [
                'residence_number_1' => 'FALLBACK',
                'residence_status_1' => ResidenceStatus::FamilyStay->value,
            ]
        );

        $this->assertSame('FALLBACK', $this->resolver->resolve($user, 'residence_number'));
        $this->assertSame('家族滞在', $this->resolver->resolve($user, 'residence_status'));
    }

    public function testResolvesResidenceStatusNullToEmptyString(): void
    {
        $user = $this->createUserWithInfo(
            [],
            [
                'residence_status_1' => null,
                'residence_status_2' => null,
                'residence_status_3' => null,
            ]
        );

        $this->assertSame('', $this->resolver->resolve($user, 'residence_status'));
    }

    public function testResolvesShortNameForSriLankaNationality(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        // Property access (loads the model, then ->update()) so
        // UserInfoShortNameObserver fires — unlike ->info()->update(...)
        // (a query-builder update on the relation) used by the other tests
        // in this file, which bypasses model events entirely.
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $user = $user->fresh();

        $this->assertSame('THARINDU RAVISHANN', $this->resolver->resolve($user, 'short_name'));
    }

    public function testResolvesShortNameFallsBackToFullNameForNonSriLankaNationality(): void
    {
        $user = User::factory()->create(['first_name' => 'Taro', 'last_name' => 'Yamada']);
        $user->info->update(['nationality' => Nationality::Vietnam->value]);
        $user = $user->fresh();

        $this->assertSame('YAMADA TARO', $this->resolver->resolve($user, 'short_name'));
    }

    public function testResolvesEmailFromUserModel(): void
    {
        $user = $this->createUserWithInfo(['email' => 'test@example.com']);

        $this->assertSame('test@example.com', $this->resolver->resolve($user, 'email'));
    }

    public function testResolvesAllStringUserInfoFields(): void
    {
        $user = $this->createUserWithInfo(
            [],
            [
                'phone' => '090-1111-2222',
                'emergency_contact_phone' => '080-3333-4444',
                'postal_code' => '100-0001',
                'prefecture' => 'Tokyo',
                'address' => 'Chiyoda-ku',
                'registered_location' => 'Shinjuku',
                'residence_number_1' => 'RN12345',
                'passport_number' => 'PN67890',
            ]
        );

        $this->assertSame('090-1111-2222', $this->resolver->resolve($user, 'phone'));
        $this->assertSame('080-3333-4444', $this->resolver->resolve($user, 'emergency_contact_phone'));
        $this->assertSame('100-0001', $this->resolver->resolve($user, 'postal_code'));
        $this->assertSame('Tokyo', $this->resolver->resolve($user, 'prefecture'));
        $this->assertSame('Chiyoda-ku', $this->resolver->resolve($user, 'address'));
        $this->assertSame('Shinjuku', $this->resolver->resolve($user, 'registered_location'));
        $this->assertSame('RN12345', $this->resolver->resolve($user, 'residence_number'));
        $this->assertSame('PN67890', $this->resolver->resolve($user, 'passport_number'));
    }

    public function testThrowsForUnknownKey(): void
    {
        $user = $this->createUserWithInfo();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown CSV property key: nonexistent');
        $this->resolver->resolve($user, 'nonexistent');
    }

    public function testHandlesUserInfoWithNullRelation(): void
    {
        $user = User::factory()->create();
        $user->info()->delete();
        $user = $user->fresh();

        $this->assertSame('', $this->resolver->resolve($user, 'phone'));
        $this->assertSame('', $this->resolver->resolve($user, 'birthday'));
        $this->assertSame('', $this->resolver->resolve($user, 'gender'));
    }

    private function createUserWithInfo(array $userAttrs = [], array $infoAttrs = []): User
    {
        $user = User::factory()->create($userAttrs);
        $user->info()->update(
            array_merge(
                [
                    'user_id' => $user->id,
                ],
                $infoAttrs
            )
        );

        return $user->fresh()->load('info');
    }
}
