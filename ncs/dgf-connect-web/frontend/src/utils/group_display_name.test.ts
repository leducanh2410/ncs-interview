import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import i18n from '@/locales/i18n';
import { getGroupDisplayName } from '@/utils/group_display_name';

describe('getGroupDisplayName', () => {
    let initialLanguage = 'ja';

    beforeAll(() => {
        initialLanguage = i18n.language;
    });

    afterAll(async () => {
        await i18n.changeLanguage(initialLanguage);
    });

    it('returns the display_name entry for the active locale', async () => {
        await i18n.changeLanguage('ja');

        expect(
            getGroupDisplayName({
                name: null,
                display_name: { en: 'Currently: Musashino', ja: '現職: Musashino', vi: 'Đang làm: Musashino' },
            }),
        ).toBe('現職: Musashino');
    });

    it('falls back to English when the active locale is not supported', async () => {
        await i18n.changeLanguage('fr');

        expect(
            getGroupDisplayName({
                name: null,
                display_name: { en: 'Currently: Musashino', ja: '現職: Musashino', vi: 'Đang làm: Musashino' },
            }),
        ).toBe('Currently: Musashino');
    });

    it('strips region suffixes when picking the locale', async () => {
        await i18n.changeLanguage('vi-VN');

        expect(
            getGroupDisplayName({
                name: null,
                display_name: { en: 'Currently: Musashino', ja: '現職: Musashino', vi: 'Đang làm: Musashino' },
            }),
        ).toBe('Đang làm: Musashino');
    });
});
