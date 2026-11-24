export type ExportBatchStatusType = 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED';

export const ExportBatchStatusEnum: { [key in ExportBatchStatusType]: { value: string; text: string } } = Object.freeze(
    {
        PENDING: { value: 'pending', text: 'Pending' },
        PROCESSING: { value: 'processing', text: 'Processing' },
        COMPLETED: { value: 'completed', text: 'Completed' },
        FAILED: { value: 'failed', text: 'Failed' },
    },
);

export const getExportBatchStatusColor = (value?: string) => {
    switch (value) {
        case ExportBatchStatusEnum.PENDING.value:
            return 'gray';
        case ExportBatchStatusEnum.PROCESSING.value:
            return 'blue';
        case ExportBatchStatusEnum.COMPLETED.value:
            return 'green';
        case ExportBatchStatusEnum.FAILED.value:
            return 'red';
        default:
            return 'gray';
    }
};
