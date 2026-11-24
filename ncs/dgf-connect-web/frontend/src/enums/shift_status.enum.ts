export type ShiftStatus = 'ENDED' | 'IN_PROGRESS' | 'COMING_SOON';

export const ShiftStatusEnum: { [key in ShiftStatus]: { value: string; text: string; color: string } } = Object.freeze({
    ENDED: { value: 'ended', text: 'Ended', color: 'gray' },
    IN_PROGRESS: { value: 'in_progress', text: 'In progress', color: 'teal' },
    COMING_SOON: { value: 'coming_soon', text: 'Coming soon', color: 'blue' },
});

export const getShiftColor = (status: string) => {
    switch (status) {
        case ShiftStatusEnum.ENDED.value:
            return 'orange.0';
        case ShiftStatusEnum.IN_PROGRESS.value:
            return 'teal.1';
        default:
            return 'gray.2';
    }
};
