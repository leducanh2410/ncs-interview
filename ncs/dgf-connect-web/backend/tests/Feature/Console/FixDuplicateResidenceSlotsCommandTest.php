<?php

namespace Tests\Feature\Console;

use App\Enums\ResidenceStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserPaper;
use App\Models\UserPaperStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixDuplicateResidenceSlotsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testCollapsesThreeCopiesOfTheSameCardIntoSlotOne(): void
    {
        $info = $this->makeInfo(
            ['AA11111111AA', '2027-01-10', ResidenceStatus::FamilyStay->value],
            ['AA11111111AA', '2027-01-10', ResidenceStatus::FamilyStay->value],
            ['AA11111111AA', '2027-01-10', ResidenceStatus::FamilyStay->value]
        );

        $this->artisan('fix:duplicate-residence-slots')->assertSuccessful();

        $info->refresh();
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('2027-01-10', $info->residence_expiry_date_1);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_1);
        $this->assertNull($info->residence_number_2);
        $this->assertNull($info->residence_expiry_date_2);
        $this->assertNull($info->residence_status_2);
        $this->assertNull($info->residence_number_3);
    }

    public function testKeepsRenewalHistoryAndDropsOnlyTheCopy(): void
    {
        $info = $this->makeInfo(
            ['UH30186026GA', '2026-07-22', ResidenceStatus::StudyAbroad->value],
            ['LJ45432927EA', '2027-05-15', ResidenceStatus::StudyAbroad->value],
            ['LJ45432927EA', '2027-05-15', ResidenceStatus::StudyAbroad->value]
        );

        $this->artisan('fix:duplicate-residence-slots')->assertSuccessful();

        $info->refresh();
        $this->assertSame('UH30186026GA', $info->residence_number_1);
        $this->assertSame('2026-07-22', $info->residence_expiry_date_1);
        $this->assertSame('LJ45432927EA', $info->residence_number_2);
        $this->assertSame('2027-05-15', $info->residence_expiry_date_2);
        $this->assertNull($info->residence_number_3);
        $this->assertNull($info->residence_expiry_date_3);
    }

    public function testMatchesCopiesCaseInsensitivelyAndIgnoringWhitespace(): void
    {
        $info = $this->makeInfo(
            ['lj24353244ec', '2028-05-26', ResidenceStatus::StudyAbroad->value],
            ["LJ2435\t3244EC", '2028-05-26', ResidenceStatus::StudyAbroad->value],
            ["LJ2435\u{3000}3244 EC", '2028-05-26', ResidenceStatus::StudyAbroad->value]
        );

        $this->artisan('fix:duplicate-residence-slots')->expectsOutputToContain('Fixed 1 user(s).')->assertSuccessful();

        $info->refresh();
        // Identical copies: the latest slot (the most recent confirm) stays, as the employee typed it
        $this->assertSame("LJ2435\u{3000}3244 EC", $info->residence_number_1);
        $this->assertSame('2028-05-26', $info->residence_expiry_date_1);
        $this->assertSame(ResidenceStatus::StudyAbroad->value, $info->residence_status_1);
        $this->assertNull($info->residence_number_2);
        $this->assertNull($info->residence_expiry_date_2);
        $this->assertNull($info->residence_number_3);
    }

    public function testLeavesCopiesThatDisagreeUntouchedWhenNoConfirmationPicksOne(): void
    {
        // Wrong expiry confirmed first, admin rejected it (clearing the confirmation),
        // then the corrected card went into slot 2 with an earlier expiry
        $rejected = $this->makeInfo(
            ['AB12345678CD', '2030-01-31', ResidenceStatus::StudyAbroad->value],
            ['AB12345678CD', '2028-01-31', ResidenceStatus::StudyAbroad->value],
            [null, null, null]
        );
        $this->makeResidenceStep($rejected, UserPaperStepStatus::NeedsReupload, null);

        // A confirmation for another card says nothing about which copy is right
        $otherCard = $this->makeInfo(
            ['AB12345678CD', '2030-01-31', ResidenceStatus::StudyAbroad->value],
            ['AB12345678CD', '2028-01-31', ResidenceStatus::FamilyStay->value],
            ['ZZ99999999ZZ', '2029-01-31', ResidenceStatus::StudyAbroad->value]
        );
        $this->makeResidenceStep($otherCard, UserPaperStepStatus::Confirmed, [
            'residence_number' => 'ZZ99999999ZZ',
            'residence_expiry_date' => '2029-01-31',
            'residence_status' => ResidenceStatus::StudyAbroad->value,
        ]);

        $this->artisan('fix:duplicate-residence-slots')
            ->expectsOutputToContain("user {$rejected->user_id}: copies of AB12345678CD differ")
            ->expectsOutputToContain("user {$otherCard->user_id}: copies of AB12345678CD differ")
            ->expectsOutputToContain('Fixed 0 user(s).')
            ->expectsOutputToContain('Skipped 2 user(s)')
            ->assertSuccessful();

        $rejected->refresh();
        $this->assertSame('2030-01-31', $rejected->residence_expiry_date_1);
        $this->assertSame('AB12345678CD', $rejected->residence_number_2);
        $this->assertSame('2028-01-31', $rejected->residence_expiry_date_2);
        $otherCard->refresh();
        $this->assertSame('2030-01-31', $otherCard->residence_expiry_date_1);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $otherCard->residence_status_2);
        $this->assertSame('ZZ99999999ZZ', $otherCard->residence_number_3);
    }

    public function testKeepsTheCopyTheEmployeeLastConfirmedEvenWithAnEarlierExpiry(): void
    {
        // Same history as above, but the employee re-confirmed the corrected card afterwards
        $info = $this->makeInfo(
            ['AB12345678CD', '2030-01-31', ResidenceStatus::StudyAbroad->value],
            ['ab12345678cd', '2028-01-31', ResidenceStatus::FamilyStay->value],
            [null, null, null]
        );
        $this->makeResidenceStep($info, UserPaperStepStatus::Confirmed, [
            'residence_number' => 'AB12345678CD',
            'residence_expiry_date' => '2028-01-31',
            'residence_status' => ResidenceStatus::FamilyStay->value,
        ]);

        $this->artisan('fix:duplicate-residence-slots')->expectsOutputToContain('Fixed 1 user(s).')->assertSuccessful();

        $info->refresh();
        $this->assertSame('ab12345678cd', $info->residence_number_1);
        $this->assertSame('2028-01-31', $info->residence_expiry_date_1);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_1);
        $this->assertNull($info->residence_number_2);
        $this->assertNull($info->residence_expiry_date_2);
        $this->assertNull($info->residence_status_2);
    }

    public function testKeepsTheConfirmedCopyWithTheLaterExpiryAndPacksTheOldCard(): void
    {
        $info = $this->makeInfo(
            ['UH30186026GA', '2026-07-22', ResidenceStatus::StudyAbroad->value],
            ['LJ45432927EA', '2027-05-15', ResidenceStatus::StudyAbroad->value],
            ['LJ45432927EA', '2029-05-15', ResidenceStatus::StudyAbroad->value]
        );
        $this->makeResidenceStep($info, UserPaperStepStatus::Confirmed, [
            'residence_number' => 'LJ45432927EA',
            'residence_expiry_date' => '2029-05-15',
            'residence_status' => ResidenceStatus::StudyAbroad->value,
        ]);

        $this->artisan('fix:duplicate-residence-slots')->expectsOutputToContain('Fixed 1 user(s).')->assertSuccessful();

        $info->refresh();
        $this->assertSame('UH30186026GA', $info->residence_number_1);
        $this->assertSame('LJ45432927EA', $info->residence_number_2);
        $this->assertSame('2029-05-15', $info->residence_expiry_date_2);
        $this->assertNull($info->residence_number_3);
    }

    public function testLeavesDistinctCardsAndDryRunUntouched(): void
    {
        $distinct = $this->makeInfo(
            ['AA11111111AA', '2025-01-01', ResidenceStatus::StudyAbroad->value],
            ['BB22222222BB', '2027-01-01', ResidenceStatus::StudyAbroad->value],
            [null, null, null]
        );
        $duplicated = $this->makeInfo(
            ['CC33333333CC', '2027-01-01', ResidenceStatus::StudyAbroad->value],
            ['CC33333333CC', '2027-01-01', ResidenceStatus::StudyAbroad->value],
            [null, null, null]
        );

        $this->artisan('fix:duplicate-residence-slots', ['--dry-run' => true])
            ->expectsOutputToContain("user {$duplicated->user_id}:")
            ->expectsOutputToContain('Would fix 1 user(s).')
            ->assertSuccessful();

        $this->assertSame('CC33333333CC', $duplicated->refresh()->residence_number_2);

        $this->artisan('fix:duplicate-residence-slots')->expectsOutputToContain('Fixed 1 user(s).')->assertSuccessful();

        $this->assertNull($duplicated->refresh()->residence_number_2);
        $distinct->refresh();
        $this->assertSame('AA11111111AA', $distinct->residence_number_1);
        $this->assertSame('BB22222222BB', $distinct->residence_number_2);
    }

    public function testUserOptionRestrictsTheRun(): void
    {
        $first = $this->makeInfo(
            ['AA11111111AA', '2027-01-01', ResidenceStatus::StudyAbroad->value],
            ['AA11111111AA', '2027-01-01', ResidenceStatus::StudyAbroad->value],
            [null, null, null]
        );
        $second = $this->makeInfo(
            ['BB22222222BB', '2027-01-01', ResidenceStatus::StudyAbroad->value],
            ['BB22222222BB', '2027-01-01', ResidenceStatus::StudyAbroad->value],
            [null, null, null]
        );

        $this->artisan('fix:duplicate-residence-slots', ['--user' => [$first->user_id]])
            ->expectsOutputToContain('Fixed 1 user(s).')
            ->assertSuccessful();

        $this->assertNull($first->refresh()->residence_number_2);
        $this->assertSame('BB22222222BB', $second->refresh()->residence_number_2);
    }

    /**
     * The residence-card step row as the real flow leaves it: Confirmed with the confirmed
     * fields (see DocumentStepConfirmTest), or NeedsReupload with them cleared after a rejection.
     */
    private function makeResidenceStep(UserInfo $info, UserPaperStepStatus $status, ?array $confirmedFields): void
    {
        $paper = UserPaper::query()->create(['user_id' => $info->user_id]);
        UserPaperStep::query()->create([
            'user_paper_id' => $paper->id,
            'step_key' => UserPaperStepKey::ResidenceCard->value,
            'status' => $status->value,
            'confirmed_at' => $confirmedFields === null ? null : now(),
            'confirmed_fields' => $confirmedFields,
        ]);
    }

    private function makeInfo(array $slot1, array $slot2, array $slot3): UserInfo
    {
        $user = User::factory()->create();
        $attributes = [];
        foreach ([1 => $slot1, 2 => $slot2, 3 => $slot3] as $slot => [$number, $expiry, $status]) {
            $attributes["residence_number_{$slot}"] = $number;
            $attributes["residence_expiry_date_{$slot}"] = $expiry;
            $attributes["residence_status_{$slot}"] = $status;
        }
        $user->info->update($attributes);

        return $user->info->refresh();
    }
}
