export type ShiftUserStatus = 'PENDING' | 'APPROVED' | 'REJECTED';

export const ShiftUserStatusEnum: {
    [key in ShiftUserStatus]: { value: string; text: string; color: string };
} = Object.freeze({
    PENDING: { value: 'pending', text: 'Pending', color: 'yellow' },
    APPROVED: { value: 'approved', text: 'Approved', color: 'green' },
    REJECTED: { value: 'rejected', text: 'Rejected', color: 'red' },
});
