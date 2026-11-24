export type PollTargetType = 'GLOBAL' | 'WORKPLACE' | 'SPECIFIC_USERS';

export const PollTargetTypeEnum: { [key in PollTargetType]: { value: string; text: string } } = Object.freeze({
    GLOBAL: { value: 'global', text: 'Global' },
    WORKPLACE: { value: 'workplace', text: 'Workplace' },
    SPECIFIC_USERS: { value: 'specific_users', text: 'Specific Users' },
});
