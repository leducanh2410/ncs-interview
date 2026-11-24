export type ExportBatchTypeKeyType = 'PDF' | 'CSV' | 'WORK_HISTORY';

export const ExportBatchTypeEnum: { [key in ExportBatchTypeKeyType]: { value: string; text: string } } = Object.freeze({
    PDF: { value: 'pdf', text: 'PDF' },
    CSV: { value: 'csv', text: 'CSV' },
    WORK_HISTORY: { value: 'work_history', text: 'Work history' },
});

export const getExportBatchTypeColor = (value?: string) => {
    switch (value) {
        case ExportBatchTypeEnum.PDF.value:
            return 'blue';
        case ExportBatchTypeEnum.CSV.value:
            return 'teal';
        case ExportBatchTypeEnum.WORK_HISTORY.value:
            return 'grape';
        default:
            return 'gray';
    }
};
