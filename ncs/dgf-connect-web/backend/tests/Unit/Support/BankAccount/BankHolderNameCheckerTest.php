<?php

namespace Tests\Unit\Support\BankAccount;

use App\Enums\NameCheckVerdict;
use App\Models\User;
use App\Support\BankAccount\BankHolderNameChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankHolderNameCheckerTest extends TestCase
{
    private BankHolderNameChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new BankHolderNameChecker();
    }

    public static function kanaCases(): array
    {
        return [
            'exact' => ['グエン バン アン', NameCheckVerdict::Match],
            'swapped order' => ['バン アン グエン', NameCheckVerdict::Match],
            'half-width no spaces' => ['ｸﾞｴﾝﾊﾞﾝｴｰ', NameCheckVerdict::Mismatch],
            'half-width same tokens joined' => ['ｸﾞｴﾎﾞﾝｽﾞｴｰ', NameCheckVerdict::Mismatch],
            'hiragana input' => ['ぐえん ばん あん', NameCheckVerdict::Match],
            'joined without spaces' => ['グエンバンアン', NameCheckVerdict::Match],
            'middle name dropped' => ['グエン アン', NameCheckVerdict::Partial],
            'different person' => ['タナカ タロウ', NameCheckVerdict::Mismatch],
            'long vowel variant' => ['グエン バーン アン', NameCheckVerdict::Match],
        ];
    }

    #[DataProvider('kanaCases')]
    public function testKanaComparison(string $holderKana, NameCheckVerdict $expected): void
    {
        $result = $this->checker->check($this->user(), $holderKana, null);

        $this->assertSame($expected->value, $result['verdict'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $result['warnings']);
        $this->assertNull($result['ai']);
    }

    public function testLatinNameMatchesRegardlessOfCaseSpacesAndHyphens(): void
    {
        $result = $this->checker->check($this->user(), 'タナカ タロウ', 'nguyen van-an');

        $this->assertSame(NameCheckVerdict::Match->value, $result['verdict']);
        $this->assertSame(NameCheckVerdict::Mismatch->value, $result['kana']['verdict']);
        $this->assertSame(NameCheckVerdict::Match->value, $result['latin']['verdict']);
    }

    public function testMissingRegisteredKanaFallsBackToLatinWithWarning(): void
    {
        $user = $this->user();
        $user->first_name_kana = null;
        $user->last_name_kana = null;

        $result = $this->checker->check($user, 'グエン バン アン', 'NGUYEN VAN AN');

        $this->assertSame(NameCheckVerdict::Match->value, $result['verdict']);
        $this->assertNull($result['kana']);
        $this->assertContains(BankHolderNameChecker::WARNING_KANA_UNAVAILABLE, $result['warnings']);
    }

    public function testNothingComparableIsAMismatch(): void
    {
        $user = $this->user();
        $user->first_name_kana = null;
        $user->last_name_kana = null;

        $result = $this->checker->check($user, 'グエン バン アン', null);

        $this->assertSame(NameCheckVerdict::Mismatch->value, $result['verdict']);
    }

    private function user(): User
    {
        $user = new User();
        $user->first_name = 'VAN AN';
        $user->last_name = 'NGUYEN';
        $user->first_name_kana = 'バン アン';
        $user->last_name_kana = 'グエン';

        return $user;
    }
}
