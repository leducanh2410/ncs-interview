export type NotificationTargetType = 'ALL' | 'WORKPLACE';

export const NotificationTargetTypeEnum: { [key in NotificationTargetType]: { value: string; text: string } } =
    Object.freeze({
        ALL: { value: 'all', text: 'All employees' },
        WORKPLACE: { value: 'workplace', text: 'Selected workplaces' },
    });
