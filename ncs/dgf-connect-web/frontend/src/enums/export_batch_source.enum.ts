export type ExportBatchSourceKeyType = 'MANUAL' | 'EXCEL';

export const ExportBatchSourceEnum: { [key in ExportBatchSourceKeyType]: { value: string; text: string } } =
    Object.freeze({
        MANUAL: { value: 'manual', text: 'Manual' },
        EXCEL: { value: 'excel', text: 'Excel' },
    });

export const getExportBatchSourceColor = (value?: string) => {
    switch (value) {
        case ExportBatchSourceEnum.EXCEL.value:
            return 'green';
        case ExportBatchSourceEnum.MANUAL.value:
            return 'gray';
        default:
            return 'gray';
    }
};
