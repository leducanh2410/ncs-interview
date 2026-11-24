<?php

namespace App\Enums;

enum SalaryRequestStatus: string
{
    use EnumTrait;

    case WaitForApproval = 'wait_for_approval';
    case WaitForApprovalAccountant = 'wait_for_approval_accountant';
    case WaitForPayment = 'wait_for_payment';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Paid = 'paid';
    case Received = 'received';
    case ReceivedConfirmed = 'received_confirmed';
    case Returned = 'returned';

    public static function getActiveStatuses(): array
    {
        return [
            self::WaitForApproval->value,
            self::WaitForApprovalAccountant->value,
            self::WaitForPayment->value,
            self::Paid->value, // Paid but not received yet
            self::Received->value, // Received by the user but not confirmed yet
        ];
    }
}
