export type AbsentNoticeStatus = 'PENDING' | 'PROCESSED' | 'REJECTED';

export const AbsentNoticeStatusEnum: {
    [key in AbsentNoticeStatus]: { value: string; text: string; color: string };
} = Object.freeze({
    PENDING: { value: 'pending', text: 'Pending', color: 'gray' },
    PROCESSED: { value: 'processed', text: 'Processed', color: 'green' },
    REJECTED: { value: 'rejected', text: 'Rejected', color: 'red' },
});
