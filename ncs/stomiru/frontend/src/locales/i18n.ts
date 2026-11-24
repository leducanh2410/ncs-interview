/**
 * i18n Internationalization System for Stomiru
 * Lightweight translation solution using CSV-derived JSON files
 */

import { getLanguage, type Language } from "@/configs/user-settings";

// Import all translation files
import ja from "./translations/ja.json";
import en from "./translations/en.json";
import zhCN from "./translations/zh-CN.json";
import zhTW from "./translations/zh-TW.json";
import vi from "./translations/vi.json";
import tl from "./translations/tl.json";
import pt from "./translations/pt.json";
import es from "./translations/es.json";
import th from "./translations/th.json";
import id from "./translations/id.json";

// Map language codes to translation objects
const translations: Record<Language, Record<string, string>> = {
    ja,
    en,
    "zh-CN": zhCN,
    "zh-TW": zhTW,
    vi,
    tl,
    pt,
    es,
    th,
    id,
};

/**
 * Get translation for a given key
 * @param key - Translation key (e.g., "loginPage-staff.title")
 * @param lang - Optional language override, defaults to current user language
 * @returns Translated string or key if not found
 */
export function t(key: string, lang?: Language): string {
    const language = lang || getLanguage();
    const translation = translations[language];

    if (translation && translation[key]) {
        return translation[key];
    }

    // Fallback to Japanese
    if (translations.ja[key]) {
        return translations.ja[key];
    }

    // Return key if no translation found
    console.warn(`Missing translation for key: ${key}`);
    return key;
}

/**
 * Get translation with variable interpolation
 * @param key - Translation key
 * @param vars - Object with variable replacements (e.g., { name: "John" })
 * @param lang - Optional language override
 */
export function tVar(key: string, vars: Record<string, string>, lang?: Language): string {
    let text = t(key, lang);
    const firstVarKey = Object.keys(vars)[0];

    Object.entries(vars).forEach(([varKey, value]) => {
        text = text.replace(new RegExp(`{{${varKey}}}|〇〇`, "g"), value);
    });

    // Handle ◯ placeholder for first/only variable
    if (firstVarKey && vars[firstVarKey]) {
        text = text.replace(/◯/g, vars[firstVarKey]);
    }

    return text;
}

/**
 * Get all translations for a specific screen
 * @param screen - Screen identifier (e.g., "loginPage-staff")
 * @param lang - Optional language override
 */
export function getScreenTranslations(screen: string, lang?: Language): Record<string, string> {
    const language = lang || getLanguage();
    const translation = translations[language] || translations.ja;

    const screenTranslations: Record<string, string> = {};

    Object.entries(translation).forEach(([key, value]) => {
        if (key.startsWith(screen)) {
            screenTranslations[key] = value;
        }
    });

    return screenTranslations;
}

export type { Language };
