export type NotificationType = 'NORMAL' | 'RECRUITMENT' | 'BANK_ACCOUNT_REQUIRED';

export const NotificationTypeEnum: { [key in NotificationType]: { value: string; text: string } } = Object.freeze({
    NORMAL: { value: 'normal', text: 'Normal' },
    RECRUITMENT: { value: 'recruitment', text: 'Recruitment' },
    BANK_ACCOUNT_REQUIRED: { value: 'bank_account_required', text: 'Bank account request' },
});

export const supportsWorkplaceTarget = (type: string | null | undefined): boolean =>
    type === NotificationTypeEnum.NORMAL.value || type === NotificationTypeEnum.BANK_ACCOUNT_REQUIRED.value;
