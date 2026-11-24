export type UserPaperType =
    | 'PASSPORT'
    | 'FOREIGNER_CARD_FRONT'
    | 'FOREIGNER_CARD_BACK'
    | 'FOREIGNER_CARD_SCAN'
    | 'STUDENT_CARD_FRONT'
    | 'STUDENT_CARD_BACK'
    | 'PORTRAIT_IMAGE'
    | 'WORK_PERMIT_STAMP'
    | 'OTHER';

export const UserPaperTypeEnum: { [key in UserPaperType]: { value: string; text: string } } = Object.freeze({
    PASSPORT: { value: 'passport', text: 'Passport' },
    FOREIGNER_CARD_FRONT: { value: 'foreigner_card_front', text: 'Foreigner Card Front' },
    FOREIGNER_CARD_BACK: { value: 'foreigner_card_back', text: 'Foreigner Card Back' },
    FOREIGNER_CARD_SCAN: { value: 'foreigner_card_scan', text: 'Foreigner Card Scan' },
    STUDENT_CARD_FRONT: { value: 'student_card_front', text: 'Student Card Front' },
    STUDENT_CARD_BACK: { value: 'student_card_back', text: 'Student Card Back' },
    PORTRAIT_IMAGE: { value: 'portrait_image', text: 'Portrait image' },
    WORK_PERMIT_STAMP: { value: 'work_permit_stamp', text: 'Work permit stamp' },
    OTHER: { value: 'other', text: 'Other' },
});
