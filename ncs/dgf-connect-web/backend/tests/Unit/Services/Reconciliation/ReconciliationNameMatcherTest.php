<?php

namespace Tests\Unit\Services\Reconciliation;

use App\Enums\ReconciliationMatchMethod;
use App\Enums\ReconciliationMatchStatus;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationNameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationNameMatcherTest extends TestCase
{
    use RefreshDatabase;

    private ReconciliationNameMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new ReconciliationNameMatcher();
    }

    public function testExactTwoTokenNameKana(): void
    {
        $user = $this->userWithKana('ウィジェシンヘ', 'サミシカ');
        $result = $this->matcher->match('ウィジェシンヘ サミシカ', collect([$user]));

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame(ReconciliationMatchMethod::ExactKana, $result['method']);
        $this->assertSame($user->id, $result['user']->id);
    }

    public function testExactOneTokenGivenName(): void
    {
        $user = $this->userWithKana('タパ', 'ウマ');
        $result = $this->matcher->match('ウマ', collect([$user]));

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame($user->id, $result['user']->id);
    }

    public function testSharedFamilyNameIsAmbiguous(): void
    {
        $a = $this->userWithKana('タパ', 'ウマ');
        $b = $this->userWithKana('タパ', 'アニータ');
        $result = $this->matcher->match('タパ', collect([$a, $b]));

        $this->assertSame(ReconciliationMatchStatus::Ambiguous, $result['status']);
        $this->assertNull($result['user']);
        $this->assertCount(2, $result['candidate_ids']);
    }

    public function testReversedGivenAndFamily(): void
    {
        $user = $this->userWithKana('ウィジェシンヘ', 'サミシカ');
        $result = $this->matcher->match('サミシカ ウィジェシンヘ', collect([$user]));

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
    }

    public function testExtraSpaceInsideAmazonNameStillMatchesExactly(): void
    {
        $user = $this->userWithKana('パテル', 'バルジュクマル');
        $result = $this->matcher->match('パテル バルジュ　クマル', collect([$user]));

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame(ReconciliationMatchMethod::ExactKana, $result['method']);
        $this->assertSame($user->id, $result['user']->id);
    }

    public function testMissingSpaceInAmazonNameStillMatchesExactly(): void
    {
        $user = $this->userWithKana('ラシュミカ', 'ラ ワン');
        $result = $this->matcher->match('ラシュミカラワン', collect([$user]));

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame($user->id, $result['user']->id);
    }

    public function testSpacesInsideReversedNameStillMatchExactly(): void
    {
        $user = $this->userWithKana('パテル', 'バルジュクマル');
        $result = $this->matcher->match('バルジュ クマル パテル', collect([$user]));

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame($user->id, $result['user']->id);
    }

    public function testShortNameLatinExact(): void
    {
        $user = User::factory()->create([
            'first_name' => 'THARINDU',
            'last_name' => 'RAVISHANN',
            'first_name_kana' => 'タリンドゥ',
            'last_name_kana' => 'ラヴィシャン',
            'short_name' => 'THARINDU RAVISHANN',
        ]);
        $result = $this->matcher->match('THARINDU RAVISHANN', collect([$user]));

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame(ReconciliationMatchMethod::ExactShort, $result['method']);
    }

    public function testNepalNullShortNameUsesFullName(): void
    {
        $user = User::factory()->create([
            'first_name' => 'SITA',
            'last_name' => 'SHARMA',
            'first_name_kana' => 'シタ',
            'last_name_kana' => 'シャルマ',
            'short_name' => null,
        ]);
        $user->refresh();

        $result = $this->matcher->match('SHARMA SITA', collect([$user]));

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame(ReconciliationMatchMethod::ExactShort, $result['method']);
    }

    public function testSimilarSubstringDoesNotAutoMap(): void
    {
        $user = $this->userWithKana('ウィジェシンヘアラッチゲ', 'サミシカ');
        $result = $this->matcher->match('ウィジェシンヘ サミシカ', collect([$user]));

        $this->assertSame(ReconciliationMatchStatus::Similar, $result['status']);
        $this->assertNull($result['user']);
        $this->assertSame([$user->id], $result['candidate_ids']);
    }

    public function testNormalizesHiraganaAndSpaces(): void
    {
        $user = $this->userWithKana('ウィジェシンヘ', 'サミシカ');
        $result = $this->matcher->match('うぃじぇしんへ　さみしか', collect([$user]));

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
    }

    public function testDoesNotExactMatchKanaToLatin(): void
    {
        $user = User::factory()->create([
            'first_name' => 'SAMISHIKA',
            'last_name' => 'WIJESINGHE',
            'first_name_kana' => 'サミシカ',
            'last_name_kana' => 'ウィジェシンヘ',
            'short_name' => 'SAMISHIKA WIJESINGHE',
        ]);
        $result = $this->matcher->match('ウィジェシンヘ サミシカ', collect([$user]));

        $this->assertNotSame(ReconciliationMatchMethod::ExactShort, $result['method']);
        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame(ReconciliationMatchMethod::ExactKana, $result['method']);
    }

    public function testPriorityExactIsAmbiguousWhenAnotherDirectoryUserAlsoExactMatches(): void
    {
        $onDuty = $this->userWithKana('タパ', 'ウマ');
        $offDuty = $this->userWithKana('タパ', 'アニータ');
        $result = $this->matcher->match('タパ', collect([$onDuty, $offDuty]), [$onDuty->id => true]);

        $this->assertSame(ReconciliationMatchStatus::Ambiguous, $result['status']);
        $this->assertNull($result['user']);
        $this->assertSame([$onDuty->id, $offDuty->id], $result['candidate_ids']);
    }

    public function testPriorityExactStaysExactWhenNoOtherDirectoryHit(): void
    {
        $onDuty = $this->userWithKana('タパ', 'ウマ');
        $unrelated = $this->userWithKana('コウノ', 'タロウ');
        $result = $this->matcher->match('ウマ', collect([$onDuty, $unrelated]), [$onDuty->id => true]);

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame($onDuty->id, $result['user']->id);
    }

    public function testFallsBackToFullDirectoryWhenPriorityPoolHasNoMatch(): void
    {
        $onDutyUnrelated = $this->userWithKana('コウノ', 'タロウ');
        $target = $this->userWithKana('タパ', 'ウマ');
        $result = $this->matcher->match('ウマ', collect([$onDutyUnrelated, $target]), [$onDutyUnrelated->id => true]);

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame($target->id, $result['user']->id);
    }

    public function testPrioritySimilarDoesNotHideExactHitInDirectory(): void
    {
        $onDutySimilar = $this->userWithKana('タパ', 'ウマル');
        $offDutyExact = $this->userWithKana('タパ', 'ウマ');
        $result = $this->matcher->match('ウマ', collect([$onDutySimilar, $offDutyExact]), [$onDutySimilar->id => true]);

        $this->assertSame(ReconciliationMatchStatus::Exact, $result['status']);
        $this->assertSame($offDutyExact->id, $result['user']->id);
        $this->assertSame([], $result['candidate_ids']);
    }

    public function testPrioritySimilarDoesNotHideOtherSimilarCandidates(): void
    {
        $onDutySimilar = $this->userWithKana('タパ', 'ウマル');
        $offDutySimilar = $this->userWithKana('サトウ', 'ウマコ');

        $result = $this->matcher->match('ウマ', collect([$onDutySimilar, $offDutySimilar]), [
            $onDutySimilar->id => true,
        ]);

        $this->assertSame(ReconciliationMatchStatus::Similar, $result['status']);
        // Being on duty only ranks a candidate first; a weak on-duty hit must
        // not drop everyone else from the modal, or the right person is
        // unreachable whenever they have no Check_In that day at all
        // (Requirement 5.4/5.5 and the no_attendance flow of 6.4).
        $this->assertSame([$onDutySimilar->id, $offDutySimilar->id], $result['candidate_ids']);
    }

    private function userWithKana(string $last, string $first): User
    {
        return User::factory()->create([
            'last_name_kana' => $last,
            'first_name_kana' => $first,
            'short_name' => null,
        ]);
    }
}
