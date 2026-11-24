export type SalaryRequestStatus =
    | 'WAIT_FOR_APPROVAL'
    | 'WAIT_FOR_APPROVAL_ACCOUNTANT'
    | 'WAIT_FOR_PAYMENT'
    | 'CANCELLED'
    | 'REJECTED'
    | 'PAID'
    | 'RECEIVED'
    | 'RECEIVED_CONFIRMED'
    | 'RETURNED';

export const SalaryRequestStatusEnum: { [key in SalaryRequestStatus]: { value: string; text: string } } = Object.freeze(
    {
        WAIT_FOR_APPROVAL: { value: 'wait_for_approval', text: 'Wait for approval' },
        WAIT_FOR_APPROVAL_ACCOUNTANT: { value: 'wait_for_approval_accountant', text: 'Wait for approval (accountant)' },
        WAIT_FOR_PAYMENT: { value: 'wait_for_payment', text: 'Wait for payment' },
        CANCELLED: { value: 'cancelled', text: 'Cancelled' },
        REJECTED: { value: 'rejected', text: 'Rejected' },
        PAID: { value: 'paid', text: 'Paid' },
        RECEIVED: { value: 'received', text: 'Received' },
        RECEIVED_CONFIRMED: { value: 'received_confirmed', text: 'Received (confirmed)' },
        RETURNED: { value: 'returned', text: 'Returned' },
    },
);
