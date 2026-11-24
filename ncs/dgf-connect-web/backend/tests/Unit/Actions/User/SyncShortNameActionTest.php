<?php

namespace Tests\Unit\Actions\User;

use App\Actions\User\SyncShortNameAction;
use App\Enums\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncShortNameActionTest extends TestCase
{
    use RefreshDatabase;

    private SyncShortNameAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(SyncShortNameAction::class);
    }

    public function testPersistsShortenedNameForSriLankaNationality(): void
    {
        $user = $this->createFreshUser([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);

        ($this->action)($user, Nationality::SriLanka->value);

        $this->assertSame('THARINDU RAVISHANN', $user->getRawOriginal('short_name'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => 'THARINDU RAVISHANN']);
    }

    public function testPersistsNullForNonSriLankaNationality(): void
    {
        $user = $this->createFreshUser(['first_name' => 'Taro', 'last_name' => 'Yamada']);

        ($this->action)($user, Nationality::Vietnam->value);

        $this->assertNull($user->getRawOriginal('short_name'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);
    }

    public function testPersistsNullForNullNationality(): void
    {
        $user = $this->createFreshUser(['first_name' => 'Taro', 'last_name' => 'Yamada']);

        ($this->action)($user, null);

        $this->assertNull($user->getRawOriginal('short_name'));
    }

    public function testWritesOnlyShortNameColumnWithoutPersistingOtherDirtyAttributes(): void
    {
        $user = $this->createFreshUser([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        ($this->action)($user, Nationality::Vietnam->value);

        $originalEmail = $user->email;
        $user->email = 'still-dirty@example.com';

        ($this->action)($user, Nationality::SriLanka->value);

        $this->assertSame('THARINDU RAVISHANN', $user->getRawOriginal('short_name'));
        $this->assertFalse($user->isDirty('short_name'));
        $this->assertTrue($user->isDirty('email'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'short_name' => 'THARINDU RAVISHANN',
            'email' => $originalEmail,
        ]);
    }

    public function testClearsPreviouslyPersistedShortNameWhenNationalityChangesAway(): void
    {
        $user = $this->createFreshUser([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        ($this->action)($user, Nationality::SriLanka->value);

        ($this->action)($user, Nationality::Vietnam->value);

        $this->assertNull($user->getRawOriginal('short_name'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'short_name' => null]);
    }

    /**
     * UserInfoFactory picks a random nationality, and its observer syncs
     * short_name through a fresh instance — refresh so the instance under
     * test carries the real persisted state instead of a stale original.
     */
    private function createFreshUser(array $attributes): User
    {
        return User::factory()->create($attributes)->refresh();
    }
}
