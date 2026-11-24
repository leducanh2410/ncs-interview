import i18n from '@/locales/i18n';

export type AdminLeaveType = 'PAID_LEAVE' | 'DAY_OFF' | 'LONG_LEAVE' | 'EXPENSE_CLAIM';

export const AdminLeaveTypeEnum: {
    [key in AdminLeaveType]: { value: string; text: string; japaneseText: string };
} = Object.freeze({
    PAID_LEAVE: { value: 'paid_leave', text: 'Paid leave', japaneseText: '有休' },
    DAY_OFF: { value: 'day_off', text: 'Day off', japaneseText: '休み' },
    LONG_LEAVE: { value: 'long_leave', text: 'Long leave', japaneseText: '長期休み' },
    EXPENSE_CLAIM: { value: 'expense_claim', text: 'Expense claim', japaneseText: '経費申請' },
});

/**
 * The translated name followed by the fixed Japanese term in parentheses, e.g. `Nghỉ phép năm
 * (有休)`, so the label matches what admins see in the mobile app regardless of viewer locale.
 */
export const getAdminLeaveTypeLabel = (value: string): string => {
    for (const key of Object.keys(AdminLeaveTypeEnum) as AdminLeaveType[]) {
        const item = AdminLeaveTypeEnum[key];
        if (item.value === value) {
            return `${i18n.t(`enum|${item.text}`)} (${item.japaneseText})`;
        }
    }
    return value;
};

export const adminLeaveTypeSelectOptions = () =>
    (Object.keys(AdminLeaveTypeEnum) as AdminLeaveType[]).map((key) => ({
        value: AdminLeaveTypeEnum[key].value,
        label: getAdminLeaveTypeLabel(AdminLeaveTypeEnum[key].value),
    }));
