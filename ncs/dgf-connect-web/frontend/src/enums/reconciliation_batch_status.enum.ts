export type ReconciliationBatchStatusKey = 'DRAFT' | 'CONFIRMED';

export const ReconciliationBatchStatusEnum: {
    [key in ReconciliationBatchStatusKey]: { value: string; text: string; color?: string };
} = {
    DRAFT: { value: 'draft', text: 'Draft', color: 'yellow' },
    CONFIRMED: { value: 'confirmed', text: 'Confirmed', color: 'green' },
};
