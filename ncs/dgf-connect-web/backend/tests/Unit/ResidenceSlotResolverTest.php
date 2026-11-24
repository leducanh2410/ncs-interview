<?php

namespace Tests\Unit;

use App\Enums\ResidenceStatus;
use App\Models\UserInfo;
use App\Support\Residence\ResidenceSlotResolver;
use PHPUnit\Framework\TestCase;

class ResidenceSlotResolverTest extends TestCase
{
    private function makeInfo(array $attributes): UserInfo
    {
        $info = new UserInfo();
        $info->setRawAttributes($attributes);

        return $info;
    }

    public function testLatestSlotReturnsNullWhenNoData(): void
    {
        $this->assertNull(ResidenceSlotResolver::latestSlot($this->makeInfo([])));
        $this->assertNull(ResidenceSlotResolver::latestSlot(null));
    }

    public function testLatestSlotPicksLatestExpiryDate(): void
    {
        $info = $this->makeInfo([
            'residence_expiry_date_1' => '2026-01-01',
            'residence_expiry_date_2' => '2028-05-01',
            'residence_expiry_date_3' => '2027-03-01',
        ]);

        $this->assertSame(2, ResidenceSlotResolver::latestSlot($info));
    }

    public function testLatestSlotPrefersLaterSlotOnTie(): void
    {
        $info = $this->makeInfo([
            'residence_expiry_date_1' => '2027-01-01',
            'residence_expiry_date_2' => '2027-01-01',
        ]);

        $this->assertSame(2, ResidenceSlotResolver::latestSlot($info));
    }

    public function testLatestSlotFallsBackToFirstSlotWithStatus(): void
    {
        $info = $this->makeInfo([
            'residence_number_1' => 'AB12345678CD',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
        ]);

        $this->assertSame(2, ResidenceSlotResolver::latestSlot($info));
    }

    public function testLatestSlotFallsBackToFirstSlotWithAnyData(): void
    {
        $info = $this->makeInfo([
            'residence_number_2' => 'AB12345678CD',
        ]);

        $this->assertSame(2, ResidenceSlotResolver::latestSlot($info));
    }

    public function testLatestIsStudyAbroad(): void
    {
        $studyAbroad = $this->makeInfo([
            'residence_expiry_date_1' => '2026-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_2' => '2028-01-01',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
        ]);
        $this->assertTrue(ResidenceSlotResolver::latestIsStudyAbroad($studyAbroad));

        $switched = $this->makeInfo([
            'residence_expiry_date_1' => '2026-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_2' => '2028-01-01',
            'residence_status_2' => ResidenceStatus::FamilyStay->value,
        ]);
        $this->assertFalse(ResidenceSlotResolver::latestIsStudyAbroad($switched));

        $this->assertFalse(ResidenceSlotResolver::latestIsStudyAbroad(null));
        $this->assertFalse(ResidenceSlotResolver::latestIsStudyAbroad($this->makeInfo([])));
    }

    public function testPrepareSlotForWriteReturnsFirstEmptySlot(): void
    {
        $info = $this->makeInfo([]);
        $this->assertSame(1, ResidenceSlotResolver::prepareSlotForWrite($info));

        $info = $this->makeInfo([
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => '2026-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
        ]);
        $this->assertSame(2, ResidenceSlotResolver::prepareSlotForWrite($info));
    }

    public function testPrepareSlotForWriteTreatsPartiallyFilledSlotAsTaken(): void
    {
        $info = $this->makeInfo([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
        ]);

        $this->assertSame(2, ResidenceSlotResolver::prepareSlotForWrite($info));
    }

    public function testPrepareSlotForWriteRotatesWhenAllSlotsFull(): void
    {
        $info = $this->makeInfo([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2024-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2026-01-01',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
            'residence_number_3' => 'CC33333333CC',
            'residence_expiry_date_3' => '2028-01-01',
            'residence_status_3' => ResidenceStatus::FamilyStay->value,
        ]);

        $slot = ResidenceSlotResolver::prepareSlotForWrite($info);

        $this->assertSame(3, $slot);
        // Oldest card (slot 1) dropped, remaining history shifted down
        $this->assertSame('BB22222222BB', $info->residence_number_1);
        $this->assertSame('2026-01-01', $info->residence_expiry_date_1);
        $this->assertSame('CC33333333CC', $info->residence_number_2);
        $this->assertSame('2028-01-01', $info->residence_expiry_date_2);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_2);
        $this->assertNull($info->residence_number_3);
        $this->assertNull($info->residence_expiry_date_3);
        $this->assertNull($info->residence_status_3);
    }

    public function testPrepareSlotForCardReusesSlotHoldingSameNumber(): void
    {
        $info = $this->makeInfo([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2026-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2028-01-01',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
        ]);

        $this->assertSame(1, ResidenceSlotResolver::prepareSlotForCard($info, 'AA11111111AA'));
        $this->assertSame(2, ResidenceSlotResolver::prepareSlotForCard($info, 'BB22222222BB'));
        // Case and whitespace differences still mean the same card
        $this->assertSame(1, ResidenceSlotResolver::prepareSlotForCard($info, ' aa11111111aa '));
        // Nothing rotated or cleared
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('BB22222222BB', $info->residence_number_2);
    }

    public function testPrepareSlotForCardTakesNextEmptySlotForNewNumber(): void
    {
        $info = $this->makeInfo([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2026-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
        ]);

        $this->assertSame(2, ResidenceSlotResolver::prepareSlotForCard($info, 'CC33333333CC'));
        $this->assertSame(2, ResidenceSlotResolver::prepareSlotForCard($info, null));
    }

    public function testPrepareSlotForCardRotatesWhenNewNumberAndAllSlotsFull(): void
    {
        $info = $this->makeInfo([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2024-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2026-01-01',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
            'residence_number_3' => 'CC33333333CC',
            'residence_expiry_date_3' => '2028-01-01',
            'residence_status_3' => ResidenceStatus::StudyAbroad->value,
        ]);

        $this->assertSame(3, ResidenceSlotResolver::prepareSlotForCard($info, 'DD44444444DD'));
        $this->assertSame('BB22222222BB', $info->residence_number_1);
        $this->assertSame('CC33333333CC', $info->residence_number_2);
        $this->assertNull($info->residence_number_3);
    }

    public function testPrepareSlotForCardDropsOtherCopiesOfSameNumberAndPacksTheRest(): void
    {
        // Legacy data: the old flow wrote the same card twice, the later copy carrying a wrong later expiry
        $info = $this->makeInfo([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2028-01-31',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'aa11111111aa',
            'residence_expiry_date_2' => '2030-01-31',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
            'residence_number_3' => 'BB22222222BB',
            'residence_expiry_date_3' => '2026-01-01',
            'residence_status_3' => ResidenceStatus::DesignatedActivities->value,
        ]);

        $this->assertSame(1, ResidenceSlotResolver::prepareSlotForCard($info, 'AA11111111AA'));
        // The copy is gone, the other card moved up and the last slot is free again
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('2028-01-31', $info->residence_expiry_date_1);
        $this->assertSame('BB22222222BB', $info->residence_number_2);
        $this->assertSame('2026-01-01', $info->residence_expiry_date_2);
        $this->assertSame(ResidenceStatus::DesignatedActivities->value, $info->residence_status_2);
        $this->assertNull($info->residence_number_3);
        $this->assertNull($info->residence_expiry_date_3);
        $this->assertNull($info->residence_status_3);
    }

    public function testPrepareSlotForCardKeepsTheFirstCopyWhenCopiesSitAfterAnotherCard(): void
    {
        $info = $this->makeInfo([
            'residence_number_1' => 'BB22222222BB',
            'residence_expiry_date_1' => '2026-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'AA11111111AA',
            'residence_expiry_date_2' => '2028-01-31',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
            'residence_number_3' => 'AA11111111AA',
            'residence_expiry_date_3' => '2028-01-31',
            'residence_status_3' => ResidenceStatus::StudyAbroad->value,
        ]);

        $this->assertSame(2, ResidenceSlotResolver::prepareSlotForCard($info, 'AA11111111AA'));
        $this->assertSame('BB22222222BB', $info->residence_number_1);
        $this->assertSame('AA11111111AA', $info->residence_number_2);
        $this->assertNull($info->residence_number_3);
        $this->assertNull($info->residence_expiry_date_3);
        $this->assertNull($info->residence_status_3);
    }

    public function testPackCardsDropsRequestedSlotsAndEmptySlotsAndTracksASlot(): void
    {
        $cards = [
            1 => ['number' => null, 'expiry' => null, 'status' => null],
            2 => [
                'number' => 'AA11111111AA',
                'expiry' => '2028-01-31',
                'status' => ResidenceStatus::StudyAbroad->value,
            ],
            3 => [
                'number' => 'BB22222222BB',
                'expiry' => '2026-01-01',
                'status' => ResidenceStatus::StudyAbroad->value,
            ],
        ];

        ['cards' => $packed, 'slot' => $slot] = ResidenceSlotResolver::packCards($cards, [2], 3);

        $this->assertSame(1, $slot);
        $this->assertSame('BB22222222BB', $packed[1]['number']);
        $this->assertNull($packed[2]['number']);
        $this->assertNull($packed[3]['number']);

        ['cards' => $packed, 'slot' => $slot] = ResidenceSlotResolver::packCards($cards, [3], 3);

        $this->assertNull($slot, 'a dropped slot is not tracked');
        $this->assertSame('AA11111111AA', $packed[1]['number']);
        $this->assertNull($packed[2]['number']);
    }

    public function testNormalizeNumber(): void
    {
        $this->assertSame('AB12345678CD', ResidenceSlotResolver::normalizeNumber(' ab1234 5678cd '));
        // Tabs, line breaks, NBSP and the full-width space are whitespace too
        $this->assertSame('AB12345678CD', ResidenceSlotResolver::normalizeNumber("ab12\t3456\r\n78cd\n"));
        $this->assertSame('AB12345678CD', ResidenceSlotResolver::normalizeNumber("AB1234\u{00A0}5678\u{3000}CD"));
        $this->assertNull(ResidenceSlotResolver::normalizeNumber(null));
        $this->assertNull(ResidenceSlotResolver::normalizeNumber('   '));
        $this->assertNull(ResidenceSlotResolver::normalizeNumber("\t\u{3000}"));
    }

    public function testNormalizedNumberSqlStripsTheSameWhitespaceAsNormalizeNumber(): void
    {
        $sql = ResidenceSlotResolver::normalizedNumberSql('residence_number_1');

        $this->assertStringStartsWith('UPPER(', $sql);
        foreach (ResidenceSlotResolver::NUMBER_WHITESPACE as $whitespace) {
            $this->assertStringContainsString("'{$whitespace}'", $sql);
        }
        $this->assertSame(count(ResidenceSlotResolver::NUMBER_WHITESPACE), substr_count($sql, 'REPLACE('));
    }
}
