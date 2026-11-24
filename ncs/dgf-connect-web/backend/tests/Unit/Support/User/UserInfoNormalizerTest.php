<?php

namespace Tests\Unit\Support\User;

use App\Support\User\UserInfoNormalizer;
use Tests\TestCase;

class UserInfoNormalizerTest extends TestCase
{
    private UserInfoNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new UserInfoNormalizer();
    }

    public function testAddressReplacesCommasWithASpace(): void
    {
        $this->assertSame('Ha Noi Cau Giay', $this->normalizer->address('Ha Noi, Cau Giay'));
    }

    public function testAddressRemovesVietnameseDiacritics(): void
    {
        $this->assertSame('Ha Noi Cau Giay', $this->normalizer->address('Hà Nội, Cầu Giấy'));
    }

    public function testAddressRemovesDStroke(): void
    {
        $this->assertSame('Dong Da', $this->normalizer->address('Đống Đa'));
    }

    public function testAddressPassesThroughNull(): void
    {
        $this->assertNull($this->normalizer->address(null));
    }

    public function testPrefectureTrimsAndLowercases(): void
    {
        $this->assertSame('tokyo', $this->normalizer->prefecture('  Tokyo  '));
    }

    public function testPrefecturePassesThroughNull(): void
    {
        $this->assertNull($this->normalizer->prefecture(null));
    }
}
