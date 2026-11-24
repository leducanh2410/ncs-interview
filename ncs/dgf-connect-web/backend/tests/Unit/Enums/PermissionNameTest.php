<?php

namespace Tests\Unit\Enums;

use App\Enums\PermissionName;
use PHPUnit\Framework\TestCase;

class PermissionNameTest extends TestCase
{
    public function testUserFacePermissionsExist()
    {
        $values = array_column(PermissionName::cases(), 'value');

        $this->assertContains('user.face.list', $values);
        $this->assertContains('user.face.create', $values);
        $this->assertContains('user.face.update', $values);
        $this->assertContains('user.face.delete', $values);
    }
}
