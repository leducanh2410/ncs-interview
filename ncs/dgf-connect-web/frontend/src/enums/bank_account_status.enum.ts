export type BankAccountStatus =
    | 'DRAFT'
    | 'OCR_PROCESSING'
    | 'OCR_READY'
    | 'OCR_FAILED'
    | 'WAIT_FOR_APPROVAL'
    | 'APPROVED'
    | 'REJECTED';

export const BankAccountStatusEnum: { [key in BankAccountStatus]: { value: string; text: string } } = Object.freeze({
    DRAFT: { value: 'draft', text: 'Draft' },
    OCR_PROCESSING: { value: 'ocr_processing', text: 'Reading card' },
    OCR_READY: { value: 'ocr_ready', text: 'Awaiting confirmation' },
    OCR_FAILED: { value: 'ocr_failed', text: 'Card unreadable' },
    WAIT_FOR_APPROVAL: { value: 'wait_for_approval', text: 'Waiting for approval' },
    APPROVED: { value: 'approved', text: 'Approved' },
    REJECTED: { value: 'rejected', text: 'Rejected' },
});

export const getBankAccountStatusColor = (status: string): string => {
    switch (status) {
        case BankAccountStatusEnum.APPROVED.value:
            return 'green';
        case BankAccountStatusEnum.REJECTED.value:
            return 'red';
        case BankAccountStatusEnum.WAIT_FOR_APPROVAL.value:
            return 'blue';
        case BankAccountStatusEnum.OCR_FAILED.value:
            return 'orange';
        default:
            return 'gray';
    }
};
