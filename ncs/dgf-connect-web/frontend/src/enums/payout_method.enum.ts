export type PayoutMethod = 'BANK_TRANSFER' | 'SEVEN_BANK_ATM';

export const PayoutMethodEnum: { [key in PayoutMethod]: { value: string; text: string } } = Object.freeze({
    BANK_TRANSFER: { value: 'bank_transfer', text: 'Bank transfer' },
    SEVEN_BANK_ATM: { value: 'seven_bank_atm', text: 'Seven Bank ATM pickup' },
});
