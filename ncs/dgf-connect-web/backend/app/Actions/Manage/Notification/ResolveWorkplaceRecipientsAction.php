<?php

namespace App\Actions\Manage\Notification;

use App\Models\Notification;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Database\Eloquent\Builder;

class ResolveWorkplaceRecipientsAction
{
    public function __construct(private readonly PersistNotificationRecipientsAction $persistRecipients) {}

    /**
     * @return array<int, int>
     */
    public function __invoke(Notification $notification): array
    {
        $workplaces = $notification->workplaces()->get(['workplaces.id', 'workplaces.is_test']);
        $workplaceIds = $workplaces->pluck('id')->all();

        if ($workplaceIds === []) {
            ($this->persistRecipients)($notification, []);

            return [];
        }

        $query = User::query()
            ->where('is_disabled', false)
            ->whereHas('workplaces', fn(Builder $query) => $query->whereIn('workplaces.id', $workplaceIds));

        if ($workplaces->every(fn(Workplace $workplace) => (bool) $workplace->is_test)) {
            $query->where('is_test', true);
        }

        $recipientIds = $query->pluck('id')->map('intval')->all();

        ($this->persistRecipients)($notification, $recipientIds);

        return $recipientIds;
    }
}
