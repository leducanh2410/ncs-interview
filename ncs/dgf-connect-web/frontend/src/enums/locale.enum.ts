export type Locale = 'VIETNAMESE' | 'ENGLISH' | 'JAPANESE' | 'NEPALI' | 'SINHALA';

export const LocaleEnum: { [key in Locale]: { value: string; text: string; flag: string } } = Object.freeze({
    VIETNAMESE: { value: 'vi', text: 'Vietnamese', flag: '🇻🇳' },
    ENGLISH: { value: 'en', text: 'English', flag: '🇬🇧' },
    JAPANESE: { value: 'ja', text: 'Japanese', flag: '🇯🇵' },
    NEPALI: { value: 'ne', text: 'Nepali', flag: '🇳🇵' },
    SINHALA: { value: 'si', text: 'Sinhala', flag: '🇱🇰' },
});

/**
 * The system's fixed base locale — the language the unsuffixed title/content fields belong
 * to. Mirrors the backend's config('app.base_locale'); keep in sync if that value ever changes.
 */
export const BASE_LOCALE = LocaleEnum.VIETNAMESE.value;

export const getTranslationLocales = (): string[] => [
    LocaleEnum.ENGLISH.value,
    LocaleEnum.JAPANESE.value,
    LocaleEnum.NEPALI.value,
    LocaleEnum.SINHALA.value,
];

/**
 * Locales the web app's own UI can actually render (see src/locales/i18n.ts `resources`).
 * Nepali/Sinhala are valid notification-translation locales but have no UI resource bundle,
 * so a language picker must not offer them or `i18n.changeLanguage` silently falls back to ja.
 */
export const UI_SUPPORTED_LOCALES = [LocaleEnum.VIETNAMESE.value, LocaleEnum.ENGLISH.value, LocaleEnum.JAPANESE.value];

export const getLocaleFlag = (locale: string): string =>
    Object.values(LocaleEnum).find((entry) => entry.value === locale)?.flag ?? '';
