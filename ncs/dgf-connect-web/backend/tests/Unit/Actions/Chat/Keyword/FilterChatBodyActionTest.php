<?php

namespace Tests\Unit\Actions\Chat\Keyword;

use App\Actions\Chat\Keyword\FilterChatBodyAction;
use App\Actions\Chat\Keyword\LoadBannedKeywordsAction;
use Tests\TestCase;

class FilterChatBodyActionTest extends TestCase
{
    private function makeAction(array $maskKeywords): FilterChatBodyAction
    {
        $load = $this->createStub(LoadBannedKeywordsAction::class);
        $load->method('__invoke')->willReturn(['block' => [], 'mask' => $maskKeywords]);

        return new FilterChatBodyAction($load);
    }

    public function testReturnsBodyUnchangedWhenNoKeywords(): void
    {
        $action = $this->makeAction([]);

        $this->assertSame('hello world', $action('hello world'));
    }

    public function testReplacesMatchedKeywordWithStars(): void
    {
        $action = $this->makeAction(['lương']);

        $this->assertSame('*** tháng này 20 triệu', $action('lương tháng này 20 triệu'));
    }

    public function testReplacesAllOccurrencesInBody(): void
    {
        $action = $this->makeAction(['lương']);

        $this->assertSame('*** và *** của tôi', $action('lương và lương của tôi'));
    }

    public function testMatchIsCaseInsensitive(): void
    {
        $action = $this->makeAction(['salary']);

        $this->assertSame('my *** is high', $action('my Salary is high'));
    }

    public function testReplacesMultipleDistinctKeywords(): void
    {
        $action = $this->makeAction(['lương', 'thuế']);

        $result = $action('lương và thuế phải đóng');

        $this->assertSame('*** và *** phải đóng', $result);
    }

    public function testReturnsNullWhenBodyIsNull(): void
    {
        $action = $this->makeAction(['lương']);

        $this->assertNull($action(null));
    }

    public function testReturnsEmptyStringUnchanged(): void
    {
        $action = $this->makeAction(['lương']);

        $this->assertSame('', $action(''));
    }

    public function testMatchesUnicodeKeyword(): void
    {
        $action = $this->makeAction(['給料']);

        $this->assertSame('私の***は高い', $action('私の給料は高い'));
    }

    public function testOverlappingKeywordsMaskTheWholeSensitiveSpan(): void
    {
        // "abc" and "bcd" overlap inside "abcd". Masking them sequentially on the rewritten body
        // would leave the trailing "d" exposed; the full span must be masked instead.
        $action = $this->makeAction(['abc', 'bcd']);

        $this->assertSame('x***y', $action('xabcdy'));
    }

    public function testShorterKeywordNestedInLongerMatchIsFullyMasked(): void
    {
        $action = $this->makeAction(['word', 'password']);

        $this->assertSame('my *** here', $action('my password here'));
    }
}
