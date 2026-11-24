<?php

namespace Tests\Unit\Actions\Common\Firebase;

use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ResolveFirebaseMessagingActionTest extends TestCase
{
    public function testReturnsNullWhenConfiguredCredentialsFileIsMissing(): void
    {
        $credentials = '/tmp/dgf-connect-missing-google-services.json';

        config([
            'firebase.default' => 'app',
            'firebase.projects.app.credentials' => $credentials,
        ]);

        Log::spy();

        $result = app(ResolveFirebaseMessagingAction::class)->execute();

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with("Firebase credentials file is missing for project 'app'; skipping FCM send.", [
                'credentials' => $credentials,
            ]);
    }
}
