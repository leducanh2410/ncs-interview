export type AdminLeaveRequestStatus = 'PENDING' | 'APPROVED' | 'REJECTED';

export const AdminLeaveRequestStatusEnum: {
    [key in AdminLeaveRequestStatus]: { value: string; text: string; color: string };
} = Object.freeze({
    PENDING: { value: 'pending', text: 'Pending', color: 'yellow' },
    APPROVED: { value: 'approved', text: 'Approved', color: 'teal' },
    REJECTED: { value: 'rejected', text: 'Rejected', color: 'red' },
});
