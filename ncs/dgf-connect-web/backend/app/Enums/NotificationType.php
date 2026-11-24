<?php

namespace App\Enums;

enum NotificationType: string
{
    use EnumTrait;

    case NORMAL = 'normal';

    case RECRUITMENT = 'recruitment';

    case SHIFT_APPROVED = 'shift_approved';

    case SHIFT_REJECTED = 'shift_rejected';

    case ABSENT_NOTICE_REJECTED = 'absent_notice_rejected';

    case SHIFT_REMINDER = 'shift_reminder';

    case CHECK_IN_REMINDER = 'check_in_reminder';

    case CHECK_OUT_REMINDER = 'check_out_reminder';

    case CONFIRM_REMINDER = 'confirm_reminder';

    case PAPER_APPROVED = 'paper_approved';

    case PAPER_REJECTED = 'paper_rejected';

    case PAPER_DOCUMENTS_REQUIRED = 'paper_documents_required';

    case STAFF_NO_ASSIGNED = 'staff_no_assigned';

    case POLL_AVAILABLE = 'poll_available';

    case CHAT_MESSAGE = 'chat_message';

    case CHAT_SUPPORT_CREATED = 'chat_support_created';

    case CHAT_ADMIN_ASSIGNED = 'chat_admin_assigned';

    case CHAT_STATE_CHANGED = 'chat_state_changed';

    case SHIFT_UNCONFIRMED_ALERT = 'shift_unconfirmed_alert';

    case SHIFT_MISSING_CHECK_IN_ALERT = 'shift_missing_check_in_alert';

    case BANK_ACCOUNT_REQUIRED = 'bank_account_required';

    case BANK_ACCOUNT_APPROVED = 'bank_account_approved';

    case BANK_ACCOUNT_REJECTED = 'bank_account_rejected';

    case ATTENDANCE_REASON_SUBMITTED = 'attendance_reason_submitted';

    public function staysInAppWhenPushFails(): bool
    {
        return in_array(
            $this,
            [
                self::PAPER_DOCUMENTS_REQUIRED,
                self::BANK_ACCOUNT_APPROVED,
                self::BANK_ACCOUNT_REJECTED,
                self::ATTENDANCE_REASON_SUBMITTED,
            ],
            true
        );
    }

    /**
     * @return string[]
     */
    public static function adminCreatableValues(): array
    {
        return [self::NORMAL->value, self::RECRUITMENT->value, self::BANK_ACCOUNT_REQUIRED->value];
    }

    public function supportsWorkplaceTarget(): bool
    {
        return $this === self::NORMAL || $this === self::BANK_ACCOUNT_REQUIRED;
    }
}
