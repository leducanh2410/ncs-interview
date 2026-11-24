<?php

namespace Tests\Unit\Traits;

use App\Models\Traits\NormalizesDiacritics;
use PHPUnit\Framework\TestCase;

class NormalizesDiacriticsTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class {
            use NormalizesDiacritics;

            public function normalize(?string $value): ?string
            {
                return $this->removeDiacritics($value);
            }
        };
    }

    public function testNullReturnsNull(): void
    {
        $this->assertNull($this->subject->normalize(null));
    }

    public function testAsciiStringIsUnchanged(): void
    {
        $this->assertSame('Nguyen Van A', $this->subject->normalize('Nguyen Van A'));
    }

    public function testEmptyStringIsUnchanged(): void
    {
        $this->assertSame('', $this->subject->normalize(''));
    }

    // đ/Đ — modified letter, handled separately
    public function testLowercaseDWithStrokeIsConvertedToD(): void
    {
        $this->assertSame('d', $this->subject->normalize('đ'));
    }

    public function testUppercaseDWithStrokeIsConvertedToD(): void
    {
        $this->assertSame('D', $this->subject->normalize('Đ'));
    }

    // Tones on plain vowels
    public function testVowelATones(): void
    {
        $this->assertSame('a', $this->subject->normalize('à')); // grave
        $this->assertSame('a', $this->subject->normalize('á')); // acute
        $this->assertSame('a', $this->subject->normalize('ả')); // hook
        $this->assertSame('a', $this->subject->normalize('ã')); // tilde
        $this->assertSame('a', $this->subject->normalize('ạ')); // dot below
    }

    // Vowel ă (breve) and its tones
    public function testVowelABreve(): void
    {
        $this->assertSame('a', $this->subject->normalize('ă'));
        $this->assertSame('a', $this->subject->normalize('ằ'));
        $this->assertSame('a', $this->subject->normalize('ắ'));
        $this->assertSame('a', $this->subject->normalize('ẳ'));
        $this->assertSame('a', $this->subject->normalize('ẵ'));
        $this->assertSame('a', $this->subject->normalize('ặ'));
    }

    // Vowel â (circumflex) and its tones
    public function testVowelACircumflex(): void
    {
        $this->assertSame('a', $this->subject->normalize('â'));
        $this->assertSame('a', $this->subject->normalize('ầ'));
        $this->assertSame('a', $this->subject->normalize('ấ'));
        $this->assertSame('a', $this->subject->normalize('ẩ'));
        $this->assertSame('a', $this->subject->normalize('ẫ'));
        $this->assertSame('a', $this->subject->normalize('ậ'));
    }

    // Vowel ê (circumflex) and its tones
    public function testVowelECircumflex(): void
    {
        $this->assertSame('e', $this->subject->normalize('ê'));
        $this->assertSame('e', $this->subject->normalize('ề'));
        $this->assertSame('e', $this->subject->normalize('ế'));
        $this->assertSame('e', $this->subject->normalize('ể'));
        $this->assertSame('e', $this->subject->normalize('ễ'));
        $this->assertSame('e', $this->subject->normalize('ệ'));
    }

    // Vowel ô (circumflex) and its tones
    public function testVowelOCircumflex(): void
    {
        $this->assertSame('o', $this->subject->normalize('ô'));
        $this->assertSame('o', $this->subject->normalize('ồ'));
        $this->assertSame('o', $this->subject->normalize('ố'));
        $this->assertSame('o', $this->subject->normalize('ổ'));
        $this->assertSame('o', $this->subject->normalize('ỗ'));
        $this->assertSame('o', $this->subject->normalize('ộ'));
    }

    // Vowel ơ (horn) and its tones
    public function testVowelOHorn(): void
    {
        $this->assertSame('o', $this->subject->normalize('ơ'));
        $this->assertSame('o', $this->subject->normalize('ờ'));
        $this->assertSame('o', $this->subject->normalize('ớ'));
        $this->assertSame('o', $this->subject->normalize('ở'));
        $this->assertSame('o', $this->subject->normalize('ỡ'));
        $this->assertSame('o', $this->subject->normalize('ợ'));
    }

    // Vowel ư (horn) and its tones
    public function testVowelUHorn(): void
    {
        $this->assertSame('u', $this->subject->normalize('ư'));
        $this->assertSame('u', $this->subject->normalize('ừ'));
        $this->assertSame('u', $this->subject->normalize('ứ'));
        $this->assertSame('u', $this->subject->normalize('ử'));
        $this->assertSame('u', $this->subject->normalize('ữ'));
        $this->assertSame('u', $this->subject->normalize('ự'));
    }

    // Uppercase variants
    public function testUppercaseVietnameseLetters(): void
    {
        $this->assertSame('A', $this->subject->normalize('À'));
        $this->assertSame('A', $this->subject->normalize('Ắ'));
        $this->assertSame('A', $this->subject->normalize('Ấ'));
        $this->assertSame('E', $this->subject->normalize('Ề'));
        $this->assertSame('O', $this->subject->normalize('Ợ'));
        $this->assertSame('U', $this->subject->normalize('Ứ'));
    }

    // Full Vietnamese name examples
    public function testCommonVietnameseLastNames(): void
    {
        $this->assertSame('Nguyen', $this->subject->normalize('Nguyễn'));
        $this->assertSame('Tran', $this->subject->normalize('Trần'));
        $this->assertSame('Le', $this->subject->normalize('Lê'));
        $this->assertSame('Pham', $this->subject->normalize('Phạm'));
        $this->assertSame('Hoang', $this->subject->normalize('Hoàng'));
        $this->assertSame('Dang', $this->subject->normalize('Đặng'));
        $this->assertSame('Bui', $this->subject->normalize('Bùi'));
        $this->assertSame('Do', $this->subject->normalize('Đỗ'));
        $this->assertSame('Ho', $this->subject->normalize('Hồ'));
        $this->assertSame('Ngo', $this->subject->normalize('Ngô'));
    }

    public function testCommonVietnameseFirstNames(): void
    {
        $this->assertSame('Thi', $this->subject->normalize('Thị'));
        $this->assertSame('Van', $this->subject->normalize('Văn'));
        $this->assertSame('Phuong', $this->subject->normalize('Phương'));
        $this->assertSame('Thanh', $this->subject->normalize('Thành'));
        $this->assertSame('Duc', $this->subject->normalize('Đức'));
        $this->assertSame('Hung', $this->subject->normalize('Hùng'));
        $this->assertSame('Linh', $this->subject->normalize('Lĩnh'));
        $this->assertSame('Anh', $this->subject->normalize('Ánh'));
    }

    public function testFullNameWithSpaces(): void
    {
        $this->assertSame('Nguyen Thi Phuong', $this->subject->normalize('Nguyễn Thị Phương'));
        $this->assertSame('Tran Van Duc', $this->subject->normalize('Trần Văn Đức'));
    }

    public function testVietnameseAddress(): void
    {
        $this->assertSame(
            '123 Duong Le Loi Quan 1 TP Ho Chi Minh',
            $this->subject->normalize('123 Đường Lê Lợi Quận 1 TP Hồ Chí Minh')
        );
    }

    public function testMixedAsciiAndVietnamese(): void
    {
        $this->assertSame('Nguyen Van A - Room 101', $this->subject->normalize('Nguyễn Văn A - Room 101'));
    }

    public function testNumbersAndSymbolsArePreserved(): void
    {
        $this->assertSame('123 456-789', $this->subject->normalize('123 456-789'));
    }

    public function testJapaneseKatakanaWithDakutenIsPreserved(): void
    {
        $this->assertSame('ブイ', $this->subject->normalize('ブイ'));
        $this->assertSame('ガギグゲゴ', $this->subject->normalize('ガギグゲゴ'));
        $this->assertSame('パピプペポ', $this->subject->normalize('パピプペポ'));
    }

    public function testJapaneseHiraganaWithDakutenIsPreserved(): void
    {
        $this->assertSame('がぎぐげご', $this->subject->normalize('がぎぐげご'));
        $this->assertSame('ばびぶべぼ', $this->subject->normalize('ばびぶべぼ'));
        $this->assertSame('ぱぴぷぺぽ', $this->subject->normalize('ぱぴぷぺぽ'));
    }

    public function testMixedVietnameseAndJapanese(): void
    {
        $this->assertSame('Nguyen ブイ', $this->subject->normalize('Nguyễn ブイ'));
    }
}
