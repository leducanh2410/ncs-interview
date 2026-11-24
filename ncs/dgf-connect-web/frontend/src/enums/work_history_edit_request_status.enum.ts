export type WorkHistoryEditRequestStatus = 'PENDING' | 'APPROVED' | 'REJECTED';

export const WorkHistoryEditRequestStatusEnum: {
    [key in WorkHistoryEditRequestStatus]: { value: string; text: string; color?: string };
} = Object.freeze({
    PENDING: { value: 'pending', text: 'Pending approval', color: 'orange' },
    APPROVED: { value: 'approved', text: 'Approved', color: 'teal' },
    REJECTED: { value: 'rejected', text: 'Rejected', color: 'red' },
});
