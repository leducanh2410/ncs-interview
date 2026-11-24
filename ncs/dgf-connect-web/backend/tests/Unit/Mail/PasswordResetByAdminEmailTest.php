<?php

namespace Tests\Unit\Mail;

use App\Mail\PasswordResetByAdminEmail;
use App\Models\User;
use Tests\TestCase;

class PasswordResetByAdminEmailTest extends TestCase
{
    public function testEmailUsesCorrectTemplateForEnglishLocale()
    {
        $user = $this->createUser(['settings' => ['lang' => 'en']]);

        $email = new PasswordResetByAdminEmail($user, 'testpass123');

        $this->assertEquals('password.reset-by-admin', $email->content()->markdown);
    }

    public function testEmailUsesCorrectTemplateForVietnameseLocale()
    {
        $user = $this->createUser(['settings' => ['lang' => 'vi']]);

        $email = new PasswordResetByAdminEmail($user, 'testpass123');

        $this->assertEquals('password.vi.reset-by-admin', $email->content()->markdown);
    }

    public function testEmailUsesCorrectTemplateForJapaneseLocale()
    {
        $user = $this->createUser(['settings' => ['lang' => 'ja']]);

        $email = new PasswordResetByAdminEmail($user, 'testpass123');

        $this->assertEquals('password.ja.reset-by-admin', $email->content()->markdown);
    }

    public function testEmailFallsBackToEnglishTemplateForNepaliLocale()
    {
        $user = $this->createUser(['settings' => ['lang' => 'ne']]);

        $email = new PasswordResetByAdminEmail($user, 'testpass123');

        $this->assertEquals('password.reset-by-admin', $email->content()->markdown);
    }

    public function testEmailFallsBackToEnglishTemplateForSinhalaLocale()
    {
        $user = $this->createUser(['settings' => ['lang' => 'si']]);

        $email = new PasswordResetByAdminEmail($user, 'testpass123');

        $this->assertEquals('password.reset-by-admin', $email->content()->markdown);
    }

    public function testEmailContainsAllRequiredData()
    {
        $user = $this->createUser();
        $password = 'testpass123';

        $email = new PasswordResetByAdminEmail($user, $password);
        $content = $email->content();

        $this->assertArrayHasKey('name', $content->with);
        $this->assertArrayHasKey('email', $content->with);
        $this->assertArrayHasKey('password', $content->with);
        $this->assertEquals($user->name, $content->with['name']);
        $this->assertEquals($user->email, $content->with['email']);
        $this->assertEquals($password, $content->with['password']);
    }

    public function testSubjectIsLocalizedForEnglishLocale()
    {
        $user = $this->createUser(['settings' => ['lang' => 'en']]);

        $email = new PasswordResetByAdminEmail($user, 'testpass123');

        $this->assertEquals('Your password has been reset', $email->subject);
    }

    public function testSubjectIsLocalizedForVietnameseLocale()
    {
        $user = $this->createUser(['settings' => ['lang' => 'vi']]);

        $email = new PasswordResetByAdminEmail($user, 'testpass123');

        $this->assertEquals('Mật khẩu của bạn đã được đặt lại', $email->subject);
    }

    public function testSubjectIsLocalizedForJapaneseLocale()
    {
        $user = $this->createUser(['settings' => ['lang' => 'ja']]);

        $email = new PasswordResetByAdminEmail($user, 'testpass123');

        $this->assertEquals('パスワードがリセットされました', $email->subject);
    }

    private function createUser(array $overrides = []): User
    {
        return new User(
            array_merge(
                [
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'first_name_kana' => 'John',
                    'last_name_kana' => 'Doe',
                    'email' => 'john@example.com',
                    'settings' => ['lang' => 'en'],
                ],
                $overrides
            )
        );
    }
}
