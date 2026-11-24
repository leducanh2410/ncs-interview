<?php

namespace App\Actions\Common\Firebase;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Laravel\Firebase\FirebaseProjectManager;
use Throwable;

class ResolveFirebaseMessagingAction
{
    /**
     * @param  string|null  $project  Firebase project key in config('firebase.projects').
     *                                Pass 'admin' for the admin app, defaults to the configured default.
     */
    public function execute(?string $project = null): ?Messaging
    {
        if (App::environment('local')) {
            return null;
        }

        $project = $project ?? config('firebase.default');
        $credentials = config("firebase.projects.{$project}.credentials");

        if (!$credentials) {
            Log::warning("Firebase credentials not configured for project '{$project}'; skipping FCM send.");

            return null;
        }

        if (is_string($credentials) && !is_file($credentials)) {
            Log::warning("Firebase credentials file is missing for project '{$project}'; skipping FCM send.", [
                'credentials' => $credentials,
            ]);

            return null;
        }

        try {
            return App::make(FirebaseProjectManager::class)->project($project)->messaging();
        } catch (Throwable $e) {
            Log::warning("Firebase messaging unavailable for project '{$project}'; skipping FCM send.", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
