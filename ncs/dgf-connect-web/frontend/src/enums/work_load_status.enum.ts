export type WorkLoadStatus = 'NORMAL' | 'NEAR' | 'OVER';

export const WorkLoadStatusEnum: { [key in WorkLoadStatus]: { value: string; text: string; color: string } } =
    Object.freeze({
        NORMAL: { value: 'normal', text: 'Within limit', color: 'teal' },
        NEAR: { value: 'near', text: 'Near limit', color: 'yellow' },
        OVER: { value: 'over', text: 'Over limit', color: 'red' },
    });
