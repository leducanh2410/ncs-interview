<?php

namespace Tests\Unit\Actions\PostalCode;

use App\Actions\PostalCode\ResolvePostalCodeFromAddressAction;
use App\Enums\PostalCodeConfidence;
use App\Support\Address\PostalCodeResolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsJapanPostalCodes;
use Tests\TestCase;

class ResolvePostalCodeFromAddressActionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsJapanPostalCodes;

    private ResolvePostalCodeFromAddressAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedJapanPostalCodes();
        $this->action = app(ResolvePostalCodeFromAddressAction::class);
    }

    public function testResolvesAResidenceCardAddressExactly(): void
    {
        $resolution = ($this->action)('埼玉県坂戸市三光町３４番地６ コピ坂戸１１０号室');

        $this->assertExact('3500235', $resolution);
        $this->assertSame(
            ['prefecture' => '埼玉県', 'city' => '坂戸市', 'town' => '三光町', 'rest' => '34番地6コピ坂戸110号室'],
            $resolution->matched->toArray()
        );
        $this->assertSame(
            [
                [
                    'postal_code' => '3500235',
                    'prefecture' => '埼玉県',
                    'city' => '坂戸市',
                    'town' => '三光町',
                    'town_detail' => null,
                ],
            ],
            $resolution->candidates
        );
    }

    public function testSameTownNameInAnotherCityIsToldApartByTheCity(): void
    {
        $this->assertExact('3500067', ($this->action)('埼玉県川越市三光町1-1'));
        $this->assertExact('3500235', ($this->action)('埼玉県坂戸市三光町1-1'));
    }

    public function testFuchuInTokyoIsNotMistakenForKyoto(): void
    {
        $resolution = ($this->action)('東京都府中市宮西町2-24');

        $this->assertExact('1830022', $resolution);
        $this->assertSame('東京都', $resolution->matched->prefecture);
    }

    public function testMatchesGunTownMunicipalitiesAndStripsOazaPrefix(): void
    {
        $this->assertExact('3500321', ($this->action)('埼玉県比企郡鳩山町大字赤沼123'));
        $this->assertExact('3500321', ($this->action)('埼玉県比企郡鳩山町赤沼123'));
    }

    public function testToleratesOcrSpacingFullWidthDigitsAndKanaVariants(): void
    {
        $this->assertExact('1830022', ($this->action)('東京都 府中市 宮西町 ２−２４'));
        $this->assertExact('0861153', ($this->action)('北海道標津郡中標津町桜ケ丘1'));
        $this->assertExact('0861153', ($this->action)('北海道標津郡中標津町桜ヶ丘1'));
    }

    public function testUsesThePrefectureHintWhenTheAddressOmitsIt(): void
    {
        $this->assertExact('3500235', ($this->action)('坂戸市三光町34-6', '埼玉県'));
        $this->assertExact('3500235', ($this->action)('坂戸市三光町34-6', 'saitama'), 'romanized hint');

        $resolution = ($this->action)('坂戸市三光町34-6');
        $this->assertSame(PostalCodeConfidence::None, $resolution->confidence);
        $this->assertNull($resolution->postalCode);
    }

    public function testChomeRangesAreSettledByTheChomeInTheAddress(): void
    {
        $this->assertExact('0600042', ($this->action)('北海道札幌市中央区大通西5丁目1-1'));
        $this->assertExact('0640820', ($this->action)('北海道札幌市中央区大通西25丁目1-1'));
        // The hyphen shorthand starts with the 丁目 in a 丁目-split town
        $this->assertExact('0640820', ($this->action)('北海道札幌市中央区大通西25-1'));
    }

    public function testChomeSplitTownWithoutAChomeInTheAddressIsAmbiguous(): void
    {
        $resolution = ($this->action)('大阪府大阪市北区梅田');

        $this->assertSame(PostalCodeConfidence::Ambiguous, $resolution->confidence);
        $this->assertNull($resolution->postalCode);
        $this->assertSame(['5300001', '5300002'], array_column($resolution->candidates, 'postal_code'));
        $this->assertSame(['１丁目', '２、３丁目'], array_column($resolution->candidates, 'town_detail'));
    }

    public function testChomeOutsideEveryRangeWithoutCatchAllIsAmbiguous(): void
    {
        $resolution = ($this->action)('大阪府大阪市北区梅田9丁目1');

        $this->assertSame(PostalCodeConfidence::Ambiguous, $resolution->confidence);
        $this->assertNull($resolution->postalCode);
    }

    public function testSplitRowsSharingOneCodeAreExactWithoutAChome(): void
    {
        $this->assertExact('1000006', ($this->action)('東京都千代田区有楽町'));
    }

    public function testEnumeratedChomeListsAreMatched(): void
    {
        $this->assertExact('5300002', ($this->action)('大阪府大阪市北区梅田3丁目1'));
    }

    public function testBanchiRangesFallBackToTheCatchAllRow(): void
    {
        $this->assertExact('0050865', ($this->action)('北海道札幌市南区常盤34番地'));
        $this->assertExact('0050863', ($this->action)('北海道札幌市南区常盤500番地'));
    }

    public function testAnExplicitUnitThatContradictsTheSplitDoesNotMatchTheRange(): void
    {
        // 5番地 is not 5丁目, so the 丁目 ranges cannot settle it
        $resolution = ($this->action)('北海道札幌市中央区大通西5番地');

        $this->assertSame(PostalCodeConfidence::Ambiguous, $resolution->confidence);
    }

    public function testPlainTownRowWinsOverBuildingRowsUnlessTheBuildingIsNamed(): void
    {
        $this->assertExact('1000005', ($this->action)('東京都千代田区丸の内1-1-1'));

        $building = ($this->action)('東京都千代田区丸の内グラントウキョウサウスタワー');
        $this->assertSame(PostalCodeConfidence::Ambiguous, $building->confidence);
        $this->assertSame(['1006601', '1006602'], array_column($building->candidates, 'postal_code'));
    }

    public function testWholeMunicipalityCodeIsExactWithoutATown(): void
    {
        $this->assertExact('1000301', ($this->action)('東京都利島村123'));
        // A village without any listed town keeps its single code whatever follows its name
        $this->assertExact('1000301', ($this->action)('東京都利島村字大平123'));
        $this->assertExact('3060433', ($this->action)('茨城県猿島郡境町123'));
        $this->assertExact('3060433', ($this->action)('茨城県猿島郡境町１２３番地'));
    }

    public function testUnrecognizedTownInACityWithABanchiFollowsCityRowIsOnlyACityDefault(): void
    {
        $this->assertExact('3060404', ($this->action)('茨城県猿島郡境町伏木123'));

        // OCR misread the town: the city-level code must not be claimed as exact
        $resolution = ($this->action)('茨城県猿島郡境町伏本123');

        $this->assertSame(PostalCodeConfidence::CityDefault, $resolution->confidence);
        $this->assertNull($resolution->postalCode);
        $this->assertNull($resolution->matched->town);
        $this->assertSame(['3060433', '3060400'], array_column($resolution->candidates, 'postal_code'));

        $nothingAfterCity = ($this->action)('茨城県猿島郡境町');
        $this->assertSame(PostalCodeConfidence::CityDefault, $nothingAfterCity->confidence);
        $this->assertNull($nothingAfterCity->postalCode);
    }

    public function testBanchiSubNumbersTellNeighbouringLotsApart(): void
    {
        $this->assertExact('0682181', ($this->action)('北海道美唄市光珠内町南2794-12番地'));
        $this->assertExact('0682181', ($this->action)('北海道美唄市光珠内町南２７９４−１２'));
        $this->assertExact('0790167', ($this->action)('北海道美唄市光珠内町南2794-13番地'));
        $this->assertExact('0790167', ($this->action)('北海道美唄市光珠内町南2795-12番地'));
        $this->assertExact('0790167', ($this->action)('北海道美唄市光珠内町南100番地'));
    }

    public function testBanchiWithoutTheSubNumberTheSplitNeedsIsAmbiguous(): void
    {
        $resolution = ($this->action)('北海道美唄市光珠内町南2794番地');

        $this->assertSame(PostalCodeConfidence::Ambiguous, $resolution->confidence);
        $this->assertNull($resolution->postalCode);
        $this->assertSame(['0682181', '0790167'], array_column($resolution->candidates, 'postal_code'));
    }

    public function testSplitByAUnitTheResolverCannotReadIsAmbiguousInsteadOfFallingBackToSonota(): void
    {
        $resolution = ($this->action)('北海道留萌市留萌原野1線123');

        $this->assertSame(PostalCodeConfidence::Ambiguous, $resolution->confidence);
        $this->assertNull($resolution->postalCode);
        $this->assertSame(['0770013', '0783165'], array_column($resolution->candidates, 'postal_code'));
    }

    public function testATownLiterallyNamedIchienIsARegularTown(): void
    {
        $this->assertExact('5220317', ($this->action)('滋賀県犬上郡多賀町一円123'));
    }

    public function testUnknownTownOffersTheCityCatchAllAsACandidateOnly(): void
    {
        $resolution = ($this->action)('埼玉県坂戸市存在しない町1-2-3');

        $this->assertSame(PostalCodeConfidence::CityDefault, $resolution->confidence);
        $this->assertNull($resolution->postalCode);
        $this->assertSame('坂戸市', $resolution->matched->city);
        $this->assertNull($resolution->matched->town);
        $this->assertSame(['3500200'], array_column($resolution->candidates, 'postal_code'));
    }

    public function testUnknownCityOrPrefectureResolvesToNothing(): void
    {
        $unknownCity = ($this->action)('埼玉県架空市1-2-3');
        $this->assertSame(PostalCodeConfidence::None, $unknownCity->confidence);
        $this->assertSame('埼玉県', $unknownCity->matched->prefecture);
        $this->assertNull($unknownCity->matched->city);
        $this->assertSame([], $unknownCity->candidates);

        $noPrefecture = ($this->action)('Somewhere 123');
        $this->assertSame(PostalCodeConfidence::None, $noPrefecture->confidence);
        $this->assertNull($noPrefecture->matched->prefecture);

        $this->assertSame(PostalCodeConfidence::None, ($this->action)('')->confidence);
    }

    public function testCityWithoutTownAndWithoutWholeCityCodeIsCityDefault(): void
    {
        $resolution = ($this->action)('東京都府中市');

        $this->assertSame(PostalCodeConfidence::CityDefault, $resolution->confidence);
        $this->assertSame(['1830000'], array_column($resolution->candidates, 'postal_code'));
    }

    public function testToArrayShape(): void
    {
        $array = ($this->action)('埼玉県坂戸市三光町34-6')->toArray();

        $this->assertSame(['postal_code', 'confidence', 'matched', 'candidates'], array_keys($array));
        $this->assertSame('3500235', $array['postal_code']);
        $this->assertSame('exact', $array['confidence']);
    }

    private function assertExact(string $expected, PostalCodeResolution $resolution, string $message = ''): void
    {
        $this->assertSame(PostalCodeConfidence::Exact, $resolution->confidence, $message ?: 'confidence');
        $this->assertSame($expected, $resolution->postalCode, $message ?: 'postal code');
        $this->assertTrue($resolution->isExact());
    }
}
