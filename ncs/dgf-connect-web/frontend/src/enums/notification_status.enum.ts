export type NotificationStatus = 'PENDING' | 'FAILED' | 'SENT';

export const NotificationStatusEnum: { [key in NotificationStatus]: { value: string; text: string } } = Object.freeze({
    PENDING: { value: 'pending', text: 'Pending' },
    FAILED: { value: 'failed', text: 'Failed' },
    SENT: { value: 'sent', text: 'Sent' },
});

export const getStatusColor = (status: string) => {
    switch (status) {
        case NotificationStatusEnum.FAILED.value:
            return 'red';
        case NotificationStatusEnum.SENT.value:
            return 'green';
        default:
            return 'blue';
    }
};
