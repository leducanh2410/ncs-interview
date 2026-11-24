<?php

namespace Tests\Unit\Actions\Workplace;

use App\Actions\Workplace\ShouldShowTestWorkplacesAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShouldShowTestWorkplacesActionTest extends TestCase
{
    use RefreshDatabase;

    private ShouldShowTestWorkplacesAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new ShouldShowTestWorkplacesAction();
    }

    public function testReturnsFalseForNormalAdminWhenFlagDisabled(): void
    {
        config()->set('app.show_test_workplaces', false);

        $user = User::factory()->create(['is_test' => false]);

        $this->assertFalse(($this->action)($user));
    }

    public function testReturnsTrueForNormalAdminWhenFlagEnabled(): void
    {
        config()->set('app.show_test_workplaces', true);

        $user = User::factory()->create(['is_test' => false]);

        $this->assertTrue(($this->action)($user));
    }

    public function testReturnsTrueForTestAdminEvenWhenFlagDisabled(): void
    {
        config()->set('app.show_test_workplaces', false);

        $user = User::factory()->create(['is_test' => true]);

        $this->assertTrue(($this->action)($user));
    }

    public function testReturnsTrueForTestAdminWhenFlagEnabled(): void
    {
        config()->set('app.show_test_workplaces', true);

        $user = User::factory()->create(['is_test' => true]);

        $this->assertTrue(($this->action)($user));
    }
}
