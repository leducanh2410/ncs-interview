<?php

namespace Tests\Unit\Actions\Chat\Keyword;

use App\Actions\Chat\Keyword\CheckMessageBlockedAction;
use App\Actions\Chat\Keyword\LoadBannedKeywordsAction;
use Tests\TestCase;

class CheckMessageBlockedActionTest extends TestCase
{
    private function makeAction(array $blockKeywords): CheckMessageBlockedAction
    {
        $load = $this->createStub(LoadBannedKeywordsAction::class);
        $load->method('__invoke')->willReturn(['block' => $blockKeywords, 'mask' => []]);

        return new CheckMessageBlockedAction($load);
    }

    public function testReturnsFalseWhenNoKeywords(): void
    {
        $action = $this->makeAction([]);

        $this->assertFalse($action('anything goes here'));
    }

    public function testReturnsTrueWhenBodyContainsBlockedKeyword(): void
    {
        $action = $this->makeAction(['cấm']);

        $this->assertTrue($action('từ này cấm rồi'));
    }

    public function testReturnsFalseWhenBodyDoesNotContainBlockedKeyword(): void
    {
        $action = $this->makeAction(['cấm']);

        $this->assertFalse($action('nội dung bình thường'));
    }

    public function testMatchIsCaseInsensitive(): void
    {
        $action = $this->makeAction(['blocked']);

        $this->assertTrue($action('this word is BLOCKED'));
    }

    public function testReturnsTrueForAnyMatchingKeyword(): void
    {
        $action = $this->makeAction(['từ_a', 'từ_b']);

        $this->assertTrue($action('có từ_b trong đây'));
    }

    public function testReturnsFalseWhenOnlyMaskKeywordIsPresent(): void
    {
        $load = $this->createStub(LoadBannedKeywordsAction::class);
        $load->method('__invoke')->willReturn(['block' => ['block_word'], 'mask' => ['mask_word']]);
        $action = new CheckMessageBlockedAction($load);

        $this->assertFalse($action('có mask_word thôi'));
    }
}
