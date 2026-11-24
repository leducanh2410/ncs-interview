export type FixedScheduleChangeRequestStatus = 'PENDING' | 'APPROVED' | 'REJECTED';

export const FixedScheduleChangeRequestStatusEnum: {
    [key in FixedScheduleChangeRequestStatus]: { value: string; text: string; color?: string };
} = Object.freeze({
    PENDING: { value: 'pending', text: 'Pending approval', color: 'orange' },
    APPROVED: { value: 'approved', text: 'Approved', color: 'teal' },
    REJECTED: { value: 'rejected', text: 'Rejected', color: 'red' },
});
