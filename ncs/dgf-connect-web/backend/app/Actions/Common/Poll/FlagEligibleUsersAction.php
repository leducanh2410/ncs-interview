<?php

namespace App\Actions\Common\Poll;

use App\Enums\Locale;
use App\Enums\NotificationType;
use App\Enums\PollTargetType;
use App\Jobs\SendPollAvailableFcmJob;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;

class FlagEligibleUsersAction
{
    public function __invoke(Poll $poll): void
    {
        $eligibleUserIds = $this->getEligibleUserIds($poll);

        if (empty($eligibleUserIds)) {
            return;
        }

        $respondedUserIds = PollResponse::query()->where('poll_id', $poll->id)->pluck('user_id')->toArray();

        $pendingUserIds = collect($eligibleUserIds)->diff($respondedUserIds)->values()->toArray();

        if (empty($pendingUserIds)) {
            return;
        }

        $chunkSize = 500;

        foreach (array_chunk($pendingUserIds, $chunkSize) as $chunk) {
            User::whereIn('id', $chunk)->update(['has_pending_votes' => true]);
        }

        $titleByLocale = [app()->getLocale() => __('poll.notification.title')];
        $contentByLocale = [app()->getLocale() => __('poll.notification.content', ['title' => $poll->title_en])];

        foreach (Locale::getTranslationLocales() as $locale) {
            $titleByLocale[$locale] = __('poll.notification.title', locale: $locale);
            $contentByLocale[$locale] = __(
                'poll.notification.content',
                ['title' => $poll->{'title_' . $locale} ?? $poll->title_en],
                locale: $locale
            );
        }

        $data = [
            'type' => NotificationType::POLL_AVAILABLE->value,
            'poll_id' => (string) $poll->id,
            'poll_type' => $poll->type->value,
        ];

        foreach (array_chunk($pendingUserIds, $chunkSize) as $chunk) {
            SendPollAvailableFcmJob::dispatch($chunk, $titleByLocale, $contentByLocale, $data);
        }
    }

    private function getEligibleUserIds(Poll $poll): array
    {
        $query = User::query()
            ->where('paper_status', 'approved')
            ->whereNotNull('staff_no')
            ->where('is_disabled', false);

        if ($poll->target_type === PollTargetType::SPECIFIC_USERS) {
            $query->whereHas('pollTargets', fn($q) => $q->where('poll_id', $poll->id));
        } elseif ($poll->target_type === PollTargetType::WORKPLACE && $poll->workplace_id !== null) {
            $query->whereHas('workplaces', fn($q) => $q->where('workplace_id', $poll->workplace_id));
        }

        return $query->pluck('id')->toArray();
    }
}
