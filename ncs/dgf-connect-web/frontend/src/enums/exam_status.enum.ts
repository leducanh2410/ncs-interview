export type ExamStatus = 'IN_PROGRESS' | 'COMPLETED' | 'FAILED' | 'PENDING_EXTERNAL';

export const ExamStatusEnum: { [key in ExamStatus]: { value: string; text: string } } = Object.freeze({
    IN_PROGRESS: { value: 'in_progress', text: 'In progress' },
    COMPLETED: { value: 'completed', text: 'Completed' },
    FAILED: { value: 'failed', text: 'Failed' },
    PENDING_EXTERNAL: { value: 'pending_external', text: 'Pending external form' },
});
