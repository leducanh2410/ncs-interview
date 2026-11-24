<?php

namespace Tests\Unit\Helpers;

use App\Helpers\NameHelper;
use Tests\TestCase;

class NameHelperTest extends TestCase
{
    public function testEmptyNameReturnsEmptyString()
    {
        $this->assertSame('', NameHelper::shortenSriLankaName(''));
    }

    public function testSingleTokenNameIsUppercased()
    {
        $this->assertSame('PERERA', NameHelper::shortenSriLankaName('perera'));
    }

    public function testNoHouseBlockKeepsBothGivenNames()
    {
        $this->assertSame('AROSHAN MANUJAYA', NameHelper::shortenSriLankaName('Aroshan Manujaya'));
    }

    public function testCollapsesHouseBlockAndKeepsFirstTwoGivenNames()
    {
        $this->assertSame(
            'THARINDU RAVISHANN',
            NameHelper::shortenSriLankaName('Karnasooriya Ragalage Tharindu Ravishann Perera')
        );
    }

    public function testFallsBackToFamilyNameWhenOnlyOneGivenNameRemains()
    {
        $this->assertSame('DESHAN LOKURUGE', NameHelper::shortenSriLankaName('Lokuruge Deshan'));
    }

    public function testPrefersRepeatedTokenAsFamilyName()
    {
        $this->assertSame('DESHAN PERERA', NameHelper::shortenSriLankaName('Perera Perera Ragalage Deshan'));
    }

    public function testReturnsCallingNameOnlyWhenNoFamilyTokenFound()
    {
        $this->assertSame('DESHAN', NameHelper::shortenSriLankaName('Deshan Deshan'));
    }

    public function testDropsTitleImmediatelyAfterHouseBlock()
    {
        $this->assertSame(
            'ESHINI PERERA',
            NameHelper::shortenSriLankaName('Chandrasekara Arachchige Don Eshini Perera')
        );
    }

    public function testSkipsTitleBetweenGivenNames()
    {
        $this->assertSame('ESHINI PERERA', NameHelper::shortenSriLankaName('Eshini Don Perera'));
    }

    public function testFallsBackToLeadingTokensWhenOnlyTitleFollowsHouseBlock()
    {
        $this->assertSame('KARNASOORIYA RAGALAGE', NameHelper::shortenSriLankaName('Karnasooriya Ragalage Don'));
    }

    public function testDoesNotTreatGeorgeAsHouseMarker()
    {
        $this->assertSame('GEORGE PERERA', NameHelper::shortenSriLankaName('George Perera Fernando'));
    }

    public function testDoesNotTreatSavageAsHouseMarker()
    {
        $this->assertSame('PERERA SAVAGE', NameHelper::shortenSriLankaName('Perera Savage Fernando'));
    }
}
