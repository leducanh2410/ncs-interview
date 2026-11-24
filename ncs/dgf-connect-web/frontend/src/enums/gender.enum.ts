export type Gender = 'MALE' | 'FEMALE' | 'OTHER';

export const GenderEnum: { [key in Gender]: { value: string; text: string } } = Object.freeze({
    MALE: { value: 'male', text: 'Male' },
    FEMALE: { value: 'female', text: 'Female' },
    OTHER: { value: 'other', text: 'Other' },
});
