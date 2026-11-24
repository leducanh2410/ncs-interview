export type ExportBatchItemStatusType = 'PENDING' | 'COMPLETED' | 'FAILED';

export const ExportBatchItemStatusEnum: { [key in ExportBatchItemStatusType]: { value: string; text: string } } =
    Object.freeze({
        PENDING: { value: 'pending', text: 'Pending' },
        COMPLETED: { value: 'completed', text: 'Completed' },
        FAILED: { value: 'failed', text: 'Failed' },
    });

export const getExportBatchItemStatusColor = (value?: string) => {
    switch (value) {
        case ExportBatchItemStatusEnum.PENDING.value:
            return 'gray';
        case ExportBatchItemStatusEnum.COMPLETED.value:
            return 'green';
        case ExportBatchItemStatusEnum.FAILED.value:
            return 'red';
        default:
            return 'gray';
    }
};
