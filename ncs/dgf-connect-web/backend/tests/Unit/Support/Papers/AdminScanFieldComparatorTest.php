<?php

namespace Tests\Unit\Support\Papers;

use App\Support\Papers\AdminScanFieldComparator;
use PHPUnit\Framework\TestCase;

/**
 * The comparison rules, held against the cases production actually produces.
 */
class AdminScanFieldComparatorTest extends TestCase
{
    private AdminScanFieldComparator $compare;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compare = new AdminScanFieldComparator();
    }

    public function testIdenticalSidesMatch(): void
    {
        $result = ($this->compare)($this->profile(), $this->card());

        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $result['verdict']);
        $this->assertSame(0, $result['mismatch_count']);
    }

    public function testNamePartsInADifferentOrderStillMatch(): void
    {
        $result = ($this->compare)($this->profile(['name' => 'NGUYEN VAN A']), $this->card(['name' => 'A VAN NGUYEN']));

        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $result['verdict']);
    }

    public function testAProfileNameInMixedCaseStillMatchesAnUpperCasedRead(): void
    {
        // users.first_name / last_name carry whatever an admin typed when creating the
        // account. Only the admin side went through OcrFieldNormalizer.
        $result = ($this->compare)($this->profile(['name' => 'Nguyen Van A']), $this->card(['name' => 'NGUYEN VAN A']));

        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $result['verdict']);
    }

    public function testAResidenceNumberTypedInLowerCaseOrWithAHyphenStillMatches(): void
    {
        // DocumentStepConfirmRules validates only min:12|max:12 and the value is stored
        // as typed, so this is a state production can genuinely be in.
        foreach (['ab12345678cd', 'AB-12345678-CD'] as $typed) {
            $result = ($this->compare)(
                $this->profile(['residence_number' => $typed]),
                $this->card(['residence_number' => 'AB12345678CD'])
            );

            $this->assertSame(
                AdminScanFieldComparator::VERDICT_MATCHED,
                $result['verdict'],
                "profile value {$typed} should not read as a mismatch"
            );
        }
    }

    public function testDatesInDifferentShapesStillMatch(): void
    {
        $result = ($this->compare)(
            $this->profile(['birthday' => '2000-01-01 00:00:00']),
            $this->card(['birthday' => '2000-01-01'])
        );

        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $result['verdict']);
    }

    public function testADifferentNameIsAMismatch(): void
    {
        $result = ($this->compare)($this->profile(), $this->card(['name' => 'TRAN THI B']));

        $this->assertSame(AdminScanFieldComparator::VERDICT_MISMATCHED, $result['verdict']);
        $this->assertSame(1, $result['mismatch_count']);
    }

    public function testAFieldOnlyOneSideHasIsUnknownAndNeverCountsAsAMismatch(): void
    {
        $result = ($this->compare)($this->profile(['birthday' => null]), $this->card());

        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $result['verdict']);
        $this->assertSame(0, $result['mismatch_count']);
        $this->assertSame(AdminScanFieldComparator::FIELD_UNKNOWN, $this->fieldVerdict($result, 'birthday'));
    }

    public function testDisplayOnlyFieldsAreNeverJudged(): void
    {
        $result = ($this->compare)(
            $this->profile(['address' => 'Tokyo Shibuya 1-2-3', 'last_name_kana' => 'グエン']),
            $this->card(['address' => '東京都渋谷区1-2-3', 'last_name_kana' => 'ｸﾞｴﾝ'])
        );

        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $result['verdict']);
        foreach (AdminScanFieldComparator::DISPLAY_ONLY_FIELDS as $field) {
            $row = $this->field($result, $field);
            $this->assertFalse($row['compared']);
            $this->assertNull($row['verdict']);
        }
    }

    public function testADifferentPrintedWordingWithTheSameMappedStatusStillMatches(): void
    {
        $result = ($this->compare)($this->profile(), $this->card(['residence_status_raw' => '留学（就労不可）']));

        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $result['verdict']);
    }

    public function testNoAdminScanIsNotUploadedEvenWhenTheProfileIsEmpty(): void
    {
        $result = ($this->compare)($this->emptyProfile(), null, residenceMissing: true);

        $this->assertSame(AdminScanFieldComparator::VERDICT_NOT_UPLOADED, $result['verdict']);
        $this->assertTrue($result['employee_residence_missing']);
    }

    public function testAnEmptyResidenceProfileIsEmployeeDataMissingWhenNothingElseDisagrees(): void
    {
        $result = ($this->compare)($this->emptyProfile(), $this->card(), residenceMissing: true);

        $this->assertSame(AdminScanFieldComparator::VERDICT_EMPLOYEE_DATA_MISSING, $result['verdict']);
        $this->assertTrue($result['employee_residence_missing']);
    }

    public function testAMismatchOutranksAMissingCardButTheMissingCardSurvivesInItsFlag(): void
    {
        // The employee this matters for: no card on file, and the scanned card names
        // somebody else. Reporting only "profile incomplete" would bury the real signal.
        $result = ($this->compare)(
            $this->emptyProfile(['name' => 'NGUYEN VAN A']),
            $this->card(['name' => 'TRAN THI B']),
            residenceMissing: true
        );

        $this->assertSame(AdminScanFieldComparator::VERDICT_MISMATCHED, $result['verdict']);
        $this->assertTrue($result['employee_residence_missing'], 'both facts must survive');
        $this->assertSame(1, $result['mismatch_count']);
    }

    public function testEveryFieldUnknownWithBothSidesPresentIsNotComparable(): void
    {
        $blank = array_fill_keys(AdminScanFieldComparator::COMPARED_FIELDS, null);

        $result = ($this->compare)($blank + ['residence_number' => 'AB12345678CD'], $blank, residenceMissing: false);

        $this->assertSame(AdminScanFieldComparator::VERDICT_NOT_COMPARABLE, $result['verdict']);
    }

    public function testACardMatchingASupersededSlotIsFlaggedRatherThanLookingUnknown(): void
    {
        $cards = [
            1 => ['number' => 'AA11111111AA', 'expiry' => '2024-01-01', 'status' => 'study_abroad'],
            2 => ['number' => 'BB22222222BB', 'expiry' => '2027-01-01', 'status' => 'study_abroad'],
            3 => ['number' => null, 'expiry' => null, 'status' => null],
        ];

        $result = ($this->compare)(
            $this->profile(['residence_number' => 'BB22222222BB']),
            $this->card(['residence_number' => 'AA11111111AA']),
            residenceMissing: false,
            cards: $cards,
            currentSlot: 2
        );

        $row = $this->field($result, 'residence_number');
        $this->assertSame(AdminScanFieldComparator::FIELD_MISMATCH, $row['verdict']);
        $this->assertSame(1, $row['matches_previous_slot'], 'the reviewer must see it is a superseded card');
    }

    public function testACardInNoSlotIsAPlainMismatchWithNoSlotFlag(): void
    {
        $cards = [
            1 => ['number' => 'AA11111111AA', 'expiry' => '2024-01-01', 'status' => 'study_abroad'],
            2 => ['number' => 'BB22222222BB', 'expiry' => '2027-01-01', 'status' => 'study_abroad'],
            3 => ['number' => null, 'expiry' => null, 'status' => null],
        ];

        $result = ($this->compare)(
            $this->profile(['residence_number' => 'BB22222222BB']),
            $this->card(['residence_number' => 'ZZ99999999ZZ']),
            residenceMissing: false,
            cards: $cards,
            currentSlot: 2
        );

        $row = $this->field($result, 'residence_number');
        $this->assertSame(AdminScanFieldComparator::FIELD_MISMATCH, $row['verdict']);
        $this->assertNull($row['matches_previous_slot']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function profile(array $overrides = []): array
    {
        return array_merge(
            [
                'name' => 'NGUYEN VAN A',
                'birthday' => '2000-01-01',
                'gender' => 'male',
                'nationality' => 'vietnam',
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2030-01-01',
                'residence_status' => 'study_abroad',
                'address' => null,
                'prefecture' => null,
                'last_name_kana' => null,
                'first_name_kana' => null,
                'residence_status_raw' => null,
            ],
            $overrides
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function emptyProfile(array $overrides = []): array
    {
        return $this->profile(
            array_merge(
                [
                    'residence_number' => null,
                    'residence_expiry_date' => null,
                    'residence_status' => null,
                ],
                $overrides
            )
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function card(array $overrides = []): array
    {
        return array_merge($this->profile(), ['residence_status_raw' => '留学'], $overrides);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function field(array $result, string $name): array
    {
        foreach ($result['fields'] as $row) {
            if ($row['field'] === $name) {
                return $row;
            }
        }

        $this->fail("field {$name} missing from the comparison");
    }

    /**
     * @param array<string, mixed> $result
     */
    private function fieldVerdict(array $result, string $name): ?string
    {
        return $this->field($result, $name)['verdict'];
    }
}
