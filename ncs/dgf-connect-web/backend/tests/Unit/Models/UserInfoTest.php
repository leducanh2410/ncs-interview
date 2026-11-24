<?php

namespace Tests\Unit\Models;

use App\Models\UserInfo;
use Tests\TestCase;

class UserInfoTest extends TestCase
{
    private function makeUserInfo(?string $address): UserInfo
    {
        $info = new UserInfo();
        $info->address = $address;

        return $info;
    }

    public function testAddressDiacriticsAreRemoved(): void
    {
        $info = $this->makeUserInfo('Đường Lê Lợi');
        $this->assertSame('Duong Le Loi', $info->address);
    }

    public function testAddressCommaWithSpaceIsReplacedWithSingleSpace(): void
    {
        $info = $this->makeUserInfo('123 Main St, District 1');
        $this->assertSame('123 Main St District 1', $info->address);
    }

    public function testAddressCommaWithoutSpaceIsReplacedWithSingleSpace(): void
    {
        $info = $this->makeUserInfo('123 Main St,District 1');
        $this->assertSame('123 Main St District 1', $info->address);
    }

    public function testAddressCommaWithMultipleSpacesIsReplacedWithSingleSpace(): void
    {
        $info = $this->makeUserInfo('123 Main St,   District 1');
        $this->assertSame('123 Main St District 1', $info->address);
    }

    public function testAddressCommasAndDiacriticsAreHandledTogether(): void
    {
        $info = $this->makeUserInfo('123 Đường Lê Lợi, Quận 1, TP Hồ Chí Minh');
        $this->assertSame('123 Duong Le Loi Quan 1 TP Ho Chi Minh', $info->address);
    }

    public function testAddressDWithStrokeIsConverted(): void
    {
        $info = $this->makeUserInfo('Đông Hà');
        $this->assertSame('Dong Ha', $info->address);
    }

    public function testNullAddressStaysNull(): void
    {
        $info = $this->makeUserInfo(null);
        $this->assertNull($info->address);
    }

    public function testAsciiAddressIsUnchanged(): void
    {
        $info = $this->makeUserInfo('123 Main Street District 1');
        $this->assertSame('123 Main Street District 1', $info->address);
    }

    public function testAddressWithNumbersAndSymbols(): void
    {
        $info = $this->makeUserInfo('Số 5 Đường Nguyễn Huệ');
        $this->assertSame('So 5 Duong Nguyen Hue', $info->address);
    }
}
