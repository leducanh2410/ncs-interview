<?php

namespace App\Actions\Common\Firebase;

use App\Models\UserDevice;
use Kreait\Firebase\Messaging\MulticastSendReport;

class PruneInvalidFirebaseTokensAction
{
    public function execute(MulticastSendReport $report): int
    {
        // A successful send in the same batch confirms that the payload and Firebase project
        // are valid. This guard prevents a credential/configuration issue from clearing an
        // entire batch of otherwise valid tokens reported as unknown to the wrong project.
        if ($report->successes()->count() === 0) {
            return 0;
        }

        $tokens = array_values(array_unique([...$report->invalidTokens(), ...$report->unknownTokens()]));
        if (empty($tokens)) {
            return 0;
        }

        return UserDevice::query()
            ->whereIn('fcm_token', $tokens)
            ->update(['fcm_token' => null]);
    }
}
