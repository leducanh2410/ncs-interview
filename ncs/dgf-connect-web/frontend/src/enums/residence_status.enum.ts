export type ResidenceStatus =
    | 'STUDY_ABROAD'
    | 'FAMILY_STAY'
    | 'DESIGNATED_ACTIVITIES'
    | 'LONG_TERM_RESIDENT'
    | 'SPOUSE_OF_PERMANENT_RESIDENT'
    | 'PERMANENT_RESIDENT'
    | 'SPOUSE_OF_JAPANESE_CITIZEN';

export const ResidenceStatusEnum: { [key in ResidenceStatus]: { value: string; text: string } } = Object.freeze({
    STUDY_ABROAD: { value: 'study_abroad', text: 'Study Abroad' },
    FAMILY_STAY: { value: 'family_stay', text: 'Family Stay' },
    DESIGNATED_ACTIVITIES: { value: 'designated_activities', text: 'Designated Activities' },
    LONG_TERM_RESIDENT: { value: 'long_term_resident', text: 'Long Term Resident' },
    SPOUSE_OF_PERMANENT_RESIDENT: { value: 'spouse_of_permanent_resident', text: 'Spouse of Permanent Resident' },
    PERMANENT_RESIDENT: { value: 'permanent_resident', text: 'Permanent Resident' },
    SPOUSE_OF_JAPANESE_CITIZEN: { value: 'spouse_of_japanese_citizen', text: 'Spouse of Japanese Citizen' },
});
