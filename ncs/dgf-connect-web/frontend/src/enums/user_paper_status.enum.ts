export type UserPaperStatus = 'REGISTERED' | 'PENDING' | 'WAIT_FOR_APPROVAL' | 'APPROVED' | 'REJECTED';

export const UserPaperStatusEnum: { [key in UserPaperStatus]: { value: string; text: string } } = Object.freeze({
    REGISTERED: { value: 'registered', text: 'Registered' },
    PENDING: { value: 'pending', text: 'Pending' },
    WAIT_FOR_APPROVAL: { value: 'wait_for_approval', text: 'Wait for approval' },
    APPROVED: { value: 'approved', text: 'Approved' },
    REJECTED: { value: 'rejected', text: 'Rejected' },
});

export const getColor = (value?: string) => {
    switch (value) {
        case UserPaperStatusEnum.REGISTERED.value:
        case UserPaperStatusEnum.PENDING.value:
            return 'yellow';
        case UserPaperStatusEnum.WAIT_FOR_APPROVAL.value:
            return 'blue';
        case UserPaperStatusEnum.APPROVED.value:
            return 'green';
        case UserPaperStatusEnum.REJECTED.value:
            return 'red';
        default:
            return 'gray';
    }
};

export const getPaperIncompleteWarning = (value: string | null | undefined, t: (key: string) => string) => {
    switch (value) {
        case UserPaperStatusEnum.REGISTERED.value:
        case UserPaperStatusEnum.PENDING.value:
            return t('Documents incomplete');
        case UserPaperStatusEnum.WAIT_FOR_APPROVAL.value:
            return t('Documents awaiting approval');
        default:
            return null;
    }
};
