import { initReactI18next } from 'react-i18next';
import i18n from 'i18next';
import LanguageDetector from 'i18next-browser-languagedetector';
import en from '@/locales/en';
import ja from '@/locales/ja';
import vi from '@/locales/vi';

i18n.use(initReactI18next)
    .use(LanguageDetector)
    .init({
        supportedLngs: ['vi', 'ja', 'en'],
        resources: { vi, ja, en },
        ns: ['translation', 'enum', 'error', 'validation', 'route', 'field', 'role', 'support_type'],
        defaultNS: 'translation',
        fallbackLng: 'ja',
        interpolation: {
            escapeValue: false,
        },
        nsSeparator: '|',
        keySeparator: '#',
        detection: {
            caches: [],
            convertDetectedLanguage: 'Iso15897',
        },
    });

export default i18n;
