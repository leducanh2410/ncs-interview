export type ImportBatchTypeKeyType = 'SHIFT_ORDER';

export const ImportBatchTypeEnum: { [key in ImportBatchTypeKeyType]: { value: string; text: string } } = Object.freeze({
    SHIFT_ORDER: { value: 'shift_order', text: 'Shift order' },
});

export const getImportBatchTypeColor = (value?: string) => {
    switch (value) {
        case ImportBatchTypeEnum.SHIFT_ORDER.value:
            return 'indigo';
        default:
            return 'gray';
    }
};
