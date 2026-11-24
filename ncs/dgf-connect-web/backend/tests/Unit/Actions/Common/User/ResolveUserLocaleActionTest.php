<?php

namespace Tests\Unit\Actions\Common\User;

use App\Actions\Common\User\ResolveUserLocaleAction;
use App\Models\User;
use Tests\TestCase;

class ResolveUserLocaleActionTest extends TestCase
{
    public function testFallsBackToStableContentLocaleWhenRuntimeLocaleChanges(): void
    {
        config(['app.base_locale' => 'vi']);
        app()->setLocale('ja');
        $user = new User(['settings' => null]);

        $locale = (new ResolveUserLocaleAction())->execute($user);

        $this->assertSame('ja', config('app.locale'));
        $this->assertSame('vi', $locale);
    }
}
