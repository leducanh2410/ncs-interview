export type CompanyHolidaySource = 'LIBRARY' | 'MANUAL';

export const CompanyHolidaySourceEnum: {
    [key in CompanyHolidaySource]: { value: string; text: string; color: string };
} = Object.freeze({
    LIBRARY: { value: 'library', text: 'Library', color: 'blue' },
    MANUAL: { value: 'manual', text: 'Manual', color: 'gray' },
});
