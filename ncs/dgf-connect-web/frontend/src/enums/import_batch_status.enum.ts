export type ImportBatchStatusType = 'PENDING' | 'COMPLETED' | 'FAILED';

export const ImportBatchStatusEnum: { [key in ImportBatchStatusType]: { value: string; text: string } } = Object.freeze(
    {
        PENDING: { value: 'pending', text: 'Pending' },
        COMPLETED: { value: 'completed', text: 'Completed' },
        FAILED: { value: 'failed', text: 'Failed' },
    },
);

export const getImportBatchStatusColor = (value?: string) => {
    switch (value) {
        case ImportBatchStatusEnum.PENDING.value:
            return 'gray';
        case ImportBatchStatusEnum.COMPLETED.value:
            return 'green';
        case ImportBatchStatusEnum.FAILED.value:
            return 'red';
        default:
            return 'gray';
    }
};
