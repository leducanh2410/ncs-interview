<?php

namespace Tests\Unit\Actions\Ocr;

use App\Actions\Ocr\ExtractJapanesePrefectureAction;
use App\Enums\Prefecture;
use PHPUnit\Framework\TestCase;

class ExtractJapanesePrefectureActionTest extends TestCase
{
    private ExtractJapanesePrefectureAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new ExtractJapanesePrefectureAction();
    }

    public function testExtractsEveryOfficialJapanesePrefectureName(): void
    {
        foreach (Prefecture::japaneseNames() as $japaneseName) {
            $this->assertSame($japaneseName, ($this->action)(null, $japaneseName . 'テスト市1-2-3'));
        }
    }

    public function testNormalizesAnExplicitAliasToOfficialJapanese(): void
    {
        $this->assertSame('東京都', ($this->action)('Tokyo-to', null));
    }

    public function testHandlesWhitespaceInsertedByOcr(): void
    {
        $this->assertSame('神奈川県', ($this->action)(null, '神 奈 川 県 横浜市中区'));
    }

    public function testChoosesTheFirstPrefectureMatchInTheAddress(): void
    {
        $this->assertSame('東京都', ($this->action)(null, '東京都府中市宮西町'));
    }

    public function testReturnsNullWhenNoPrefectureCanBeRecognized(): void
    {
        $this->assertNull(($this->action)(null, '住所不明'));
    }
}
