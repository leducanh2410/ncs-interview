export const paperStepLabel = (step: string, t: (key: string) => string) => {
    switch (step) {
        case 'residence_card':
            return t('Residence card');
        case 'passport':
            return t('Passport');
        case 'portrait':
            return t('Portrait image');
        case 'student_card':
            return t('Student card');
        case 'work_permit':
            return t('Work permit stamp');
        case 'other':
            return t('Other');
        default:
            return step;
    }
};
