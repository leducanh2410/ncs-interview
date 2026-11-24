"use client";

import { cloneElement, isValidElement, type ReactNode, useState, useEffect, useCallback } from "react";
import { t, tVar, getScreenTranslations, type Language } from "@/locales/i18n";
import { getLanguage, setLanguage as setStoredLanguage } from "@/configs/user-settings";

type TranslationVarWrappers = Record<string, (value: string) => ReactNode>;

function interpolateWithWrappers(
    template: string,
    vars: Record<string, string>,
    wrappers: TranslationVarWrappers,
): ReactNode {
    const firstVarKey = Object.keys(vars)[0] || "";
    const tokenRegex = /{{(.*?)}}|〇〇|◯/g;
    const parts: ReactNode[] = [];

    let lastIndex = 0;
    let match: RegExpExecArray | null;

    while ((match = tokenRegex.exec(template)) !== null) {
        const [token, capturedVarKey] = match;
        // Handle ◯ placeholder - use first variable
        const varKey = capturedVarKey || firstVarKey;

        if (match.index > lastIndex) {
            parts.push(template.slice(lastIndex, match.index));
        }

        const value = vars[varKey];
        const wrapper = wrappers[varKey];

        if (value !== undefined) {
            const wrappedValue = wrapper ? wrapper(value) : value;
            if (isValidElement(wrappedValue)) {
                parts.push(cloneElement(wrappedValue, { key: `var-${parts.length}` }));
            } else {
                parts.push(wrappedValue);
            }
        } else {
            parts.push(token);
        }

        lastIndex = tokenRegex.lastIndex;
    }

    if (lastIndex < template.length) {
        parts.push(template.slice(lastIndex));
    }

    if (parts.length === 0) {
        return template;
    }

    return parts;
}

/**
 * React hook for using translations in components
 * Provides reactive translation function that updates when language changes
 */
export function useTranslation() {
    const [currentLanguage, setCurrentLanguage] = useState<Language>(getLanguage);

    // Listen for language changes from other sources
    useEffect(() => {
        const handleStorageChange = (e: StorageEvent) => {
            if (e.key === "stomiru_user_settings") {
                setCurrentLanguage(getLanguage());
            }
        };

        const handleLanguageChange = () => {
            setCurrentLanguage(getLanguage());
        };

        window.addEventListener("storage", handleStorageChange);
        window.addEventListener("languagechange", handleLanguageChange);
        return () => {
            window.removeEventListener("storage", handleStorageChange);
            window.removeEventListener("languagechange", handleLanguageChange);
        };
    }, []);

    /**
     * Change the current language
     */
    const changeLanguage = useCallback((lang: Language) => {
        setStoredLanguage(lang);
        setCurrentLanguage(lang);
    }, []);

    /**
     * Translate a key
     */
    const translate = useCallback((key: string) => {
        return t(key, currentLanguage);
    }, [currentLanguage]);

    /**
     * Translate with variable interpolation
     */
    const translateVar = useCallback((
        key: string,
        vars: Record<string, string>,
        wrappers?: TranslationVarWrappers,
    ): ReactNode => {
        if (!wrappers) {
            return tVar(key, vars, currentLanguage);
        }

        const template = t(key, currentLanguage);
        return interpolateWithWrappers(template, vars, wrappers);
    }, [currentLanguage]);

    /**
     * Get all translations for a screen
     */
    const getScreen = useCallback((screen: string) => {
        return getScreenTranslations(screen, currentLanguage);
    }, [currentLanguage]);

    return {
        t: translate,
        tVar: translateVar,
        getScreen,
        language: currentLanguage,
        setLanguage: changeLanguage,
    };
}

/**
 * Hook to listen for language changes across the app
 */
export function useLanguageChange(callback: (lang: Language) => void) {
    useEffect(() => {
        const handleChange = () => {
            callback(getLanguage());
        };

        window.addEventListener("languagechange", handleChange);
        return () => window.removeEventListener("languagechange", handleChange);
    }, [callback]);
}

export type { Language };
