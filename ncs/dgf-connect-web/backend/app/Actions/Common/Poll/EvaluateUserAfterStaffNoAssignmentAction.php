<?php

namespace App\Actions\Common\Poll;

use App\Enums\Locale;
use App\Enums\NotificationType;
use App\Jobs\SendPollAvailableFcmJob;
use App\Models\User;

class EvaluateUserAfterStaffNoAssignmentAction
{
    private GetPendingPollsAction $getPendingPollsAction;

    public function __construct(GetPendingPollsAction $getPendingPollsAction)
    {
        $this->getPendingPollsAction = $getPendingPollsAction;
    }

    public function __invoke(User $user): void
    {
        if ($user->staff_no === null) {
            return;
        }

        $pendingConfigs = $this->getPendingPollsAction->__invoke($user);
        $wasPending = $user->has_pending_votes;
        $isPending = $pendingConfigs->isNotEmpty();

        User::where('id', $user->id)->update(['has_pending_votes' => $isPending]);

        if (!$wasPending && $isPending) {
            $this->sendPollAvailableFcm($user);
        }
    }

    private function sendPollAvailableFcm(User $user): void
    {
        $titleByLocale = [app()->getLocale() => __('poll.notification.title')];
        $contentByLocale = [app()->getLocale() => __('poll.notification.new_user_content')];

        foreach (Locale::getTranslationLocales() as $locale) {
            $titleByLocale[$locale] = __('poll.notification.title', locale: $locale);
            $contentByLocale[$locale] = __('poll.notification.new_user_content', locale: $locale);
        }

        SendPollAvailableFcmJob::dispatch([$user->id], $titleByLocale, $contentByLocale, [
            'type' => NotificationType::POLL_AVAILABLE->value,
        ]);
    }
}
