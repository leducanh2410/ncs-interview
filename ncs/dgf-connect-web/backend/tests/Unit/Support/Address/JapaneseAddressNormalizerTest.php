<?php

namespace Tests\Unit\Support\Address;

use App\Support\Address\JapaneseAddressNormalizer;
use PHPUnit\Framework\TestCase;

class JapaneseAddressNormalizerTest extends TestCase
{
    public function testFoldsFullWidthDigitsAndRemovesEveryKindOfSpace(): void
    {
        $this->assertSame(
            '埼玉県坂戸市三光町34番地6コピ坂戸110号室',
            JapaneseAddressNormalizer::normalize("埼玉県 坂戸市　三光町３４番地６\tコピ坂戸１１０号室 ")
        );
    }

    public function testUnifiesSmallKanaVariants(): void
    {
        $this->assertSame('桜ケ丘', JapaneseAddressNormalizer::normalize('桜ヶ丘'));
        $this->assertSame('三カ日', JapaneseAddressNormalizer::normalize('三ヵ日'));
        $this->assertSame('桜ケ丘', JapaneseAddressNormalizer::normalize('桜ｹ丘'), 'half-width kana');
    }

    public function testNullBecomesEmptyString(): void
    {
        $this->assertSame('', JapaneseAddressNormalizer::normalize(null));
    }

    public function testStripsOnlyALeadingAzaPrefix(): void
    {
        $this->assertSame('赤沼', JapaneseAddressNormalizer::stripAzaPrefix('大字赤沼'));
        $this->assertSame('赤沼', JapaneseAddressNormalizer::stripAzaPrefix('字赤沼'));
        $this->assertSame('東大字', JapaneseAddressNormalizer::stripAzaPrefix('東大字'));
    }

    public function testNormalizesPostalCodesToSevenDigits(): void
    {
        $this->assertSame('3500235', JapaneseAddressNormalizer::normalizePostalCode('350-0235'));
        $this->assertSame('3500235', JapaneseAddressNormalizer::normalizePostalCode('〒３５０−０２３５'));
        $this->assertSame('3500235', JapaneseAddressNormalizer::normalizePostalCode(' 3500235 '));
        $this->assertNull(JapaneseAddressNormalizer::normalizePostalCode('350023'));
        $this->assertNull(JapaneseAddressNormalizer::normalizePostalCode('abc'));
        $this->assertNull(JapaneseAddressNormalizer::normalizePostalCode(null));
    }
}
