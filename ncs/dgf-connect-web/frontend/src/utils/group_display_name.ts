import i18n from '@/locales/i18n';
import { Group, GroupDisplayName } from '@/types/group';

type DisplayLocale = keyof GroupDisplayName;

const DEFAULT_LOCALE: DisplayLocale = 'en';

const resolveLocale = (language: string): DisplayLocale => {
    const base = language.split('-')[0] as DisplayLocale;
    if (base === 'en' || base === 'ja' || base === 'vi') {
        return base;
    }
    return DEFAULT_LOCALE;
};

export const getGroupDisplayName = (group: Pick<Group, 'display_name' | 'name'>): string => {
    const locale = resolveLocale(i18n.language);
    return group.display_name?.[locale] ?? group.display_name?.[DEFAULT_LOCALE] ?? group.name ?? '';
};
