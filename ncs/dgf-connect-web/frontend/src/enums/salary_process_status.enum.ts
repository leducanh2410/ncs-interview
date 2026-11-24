export type SalaryProcessStatus = 'PENDING' | 'PROCESSING' | 'COMPLETED';

export const SalaryProcessStatusEnum: { [key in SalaryProcessStatus]: { value: string; text: string } } = Object.freeze(
    {
        PENDING: { value: 'pending', text: 'Pending' },
        PROCESSING: { value: 'processing', text: 'Processing' },
        COMPLETED: { value: 'completed', text: 'Completed' },
    },
);
