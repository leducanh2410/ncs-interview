<?php

namespace App\Actions\Manage\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Models\Notification;
use RuntimeException;

class ResolveNotificationRecipientsAction
{
    public function __construct(
        private readonly ResolveRecruitmentRecipientsAction $resolveRecruitmentRecipients,
        private readonly ResolveWorkplaceRecipientsAction $resolveWorkplaceRecipients
    ) {}

    /**
     * @return array<int, int>
     */
    public function __invoke(Notification $notification): array
    {
        if ($notification->type === NotificationType::RECRUITMENT->value) {
            return ($this->resolveRecruitmentRecipients)($notification);
        }

        if ($notification->target_type === NotificationTargetType::Workplace->value) {
            return ($this->resolveWorkplaceRecipients)($notification);
        }

        throw new RuntimeException("Notification {$notification->id} is not targeted");
    }
}
