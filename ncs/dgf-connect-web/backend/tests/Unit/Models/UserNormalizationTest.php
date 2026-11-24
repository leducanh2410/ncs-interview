<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\TestCase;

class UserNormalizationTest extends TestCase
{
    private function makeUser(array $attributes): User
    {
        $user = new User();
        foreach ($attributes as $key => $value) {
            $user->$key = $value;
        }

        return $user;
    }

    public function testFirstNameIsUppercasedAndDiacriticsRemoved(): void
    {
        $user = $this->makeUser(['first_name' => 'Phương']);
        $this->assertSame('PHUONG', $user->first_name);
    }

    public function testLastNameIsUppercasedAndDiacriticsRemoved(): void
    {
        $user = $this->makeUser(['last_name' => 'Nguyễn']);
        $this->assertSame('NGUYEN', $user->last_name);
    }

    public function testFirstNameKanaIsUppercasedAndDiacriticsRemoved(): void
    {
        $user = $this->makeUser(['first_name_kana' => 'Phương']);
        $this->assertSame('PHUONG', $user->first_name_kana);
    }

    public function testLastNameKanaIsUppercasedAndDiacriticsRemoved(): void
    {
        $user = $this->makeUser(['last_name_kana' => 'Trần']);
        $this->assertSame('TRAN', $user->last_name_kana);
    }

    public function testDWithStrokeInFirstNameIsUppercased(): void
    {
        $user = $this->makeUser(['first_name' => 'Đức']);
        $this->assertSame('DUC', $user->first_name);
    }

    public function testDWithStrokeInLastNameIsUppercased(): void
    {
        $user = $this->makeUser(['last_name' => 'Đặng']);
        $this->assertSame('DANG', $user->last_name);
    }

    public function testAsciiFirstNameIsUppercased(): void
    {
        $user = $this->makeUser(['first_name' => 'Nguyen']);
        $this->assertSame('NGUYEN', $user->first_name);
    }

    public function testNullFirstNameStaysNull(): void
    {
        $user = $this->makeUser(['first_name' => null]);
        $this->assertNull($user->first_name);
    }

    public function testNullLastNameStaysNull(): void
    {
        $user = $this->makeUser(['last_name' => null]);
        $this->assertNull($user->last_name);
    }

    public function testNullFirstNameKanaStaysNull(): void
    {
        $user = $this->makeUser(['first_name_kana' => null]);
        $this->assertNull($user->first_name_kana);
    }

    public function testNullLastNameKanaStaysNull(): void
    {
        $user = $this->makeUser(['last_name_kana' => null]);
        $this->assertNull($user->last_name_kana);
    }

    public function testFullNameNormalization(): void
    {
        $user = $this->makeUser([
            'first_name' => 'Thị Phương',
            'last_name' => 'Nguyễn',
        ]);
        $this->assertSame('THI PHUONG', $user->first_name);
        $this->assertSame('NGUYEN', $user->last_name);
    }
}
