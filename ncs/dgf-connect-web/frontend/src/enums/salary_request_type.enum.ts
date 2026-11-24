export type SalaryRequestType = 'TRANSFER' | 'CASH';

export const SalaryRequestTypeEnum: { [key in SalaryRequestType]: { value: string; text: string } } = Object.freeze({
    TRANSFER: { value: 'transfer', text: 'Transfer' },
    CASH: { value: 'cash', text: 'Cash' },
});
