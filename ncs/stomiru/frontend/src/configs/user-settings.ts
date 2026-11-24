// User settings storage for i18n and preferences
import ApiHelper from "@/helpers/api.helper";

// Language is stored in user settings and sent to backend
export type Language =
  | "ja"
  | "en"
  | "zh-CN"
  | "zh-TW"
  | "vi"
  | "tl"
  | "th"
  | "pt"
  | "es"
  | "id";

// Language names for display
export const languageNames: Record<Language, string> = {
  ja: "日本語",
  en: "English",
  "zh-CN": "简体中文",
  "zh-TW": "繁體中文",
  vi: "Tiếng Việt",
  tl: "Tagalog",
  th: "ไทย",
  pt: "Português",
  es: "Español",
  id: "Bahasa Indonesia",
};

const LANGUAGE_CODES = new Set<Language>(
  Object.keys(languageNames) as Language[]
);

// Get all available languages
export function getAvailableLanguages() {
  return Object.entries(languageNames).map(([code, name]) => ({
    code: code as Language,
    name,
  }));
}

export interface UserSettings {
  language: Language;
}

const DEFAULT_SETTINGS: UserSettings = {
  language: "ja",
};

export const DEFAULT_LANGUAGE: Language = DEFAULT_SETTINGS.language;
export const USER_SETTINGS_STORAGE_KEY = "stomiru_user_settings";

export function isLanguage(value: unknown): value is Language {
  return typeof value === "string" && LANGUAGE_CODES.has(value as Language);
}

const dispatchLanguageChange = () => {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new Event("languagechange"));
  }
};

export function getUserSettings(): UserSettings {
  if (typeof window === "undefined") {
    return DEFAULT_SETTINGS;
  }

  try {
    const stored = localStorage.getItem(USER_SETTINGS_STORAGE_KEY);
    if (stored) {
      const parsed = JSON.parse(stored) as Partial<UserSettings>;
      return {
        ...DEFAULT_SETTINGS,
        ...parsed,
        language: isLanguage(parsed.language)
          ? parsed.language
          : DEFAULT_SETTINGS.language,
      };
    }
  } catch (e) {
    console.error("Failed to load user settings:", e);
  }

  return DEFAULT_SETTINGS;
}

export function saveUserSettings(
  settings: Partial<UserSettings>
): UserSettings {
  const currentSettings = getUserSettings();
  const newSettings = { ...currentSettings, ...settings };

  if (typeof window !== "undefined") {
    try {
      localStorage.setItem(
        USER_SETTINGS_STORAGE_KEY,
        JSON.stringify(newSettings)
      );
    } catch (e) {
      console.error("Failed to save user settings:", e);
    }
  }

  return newSettings;
}

export function getLanguage(): Language {
  return getUserSettings().language;
}

export function setLanguage(language: Language): void {
  saveUserSettings({ language });
  dispatchLanguageChange();
}

export const setLanguageWithApi = async (
  language: Language,
  syncWithBackend: boolean
): Promise<void> => {
  // Save locally first
  setLanguage(language);

  // Then call API to persist on backend
  try {
    if (syncWithBackend) {
      await ApiHelper.put(
        "auth/language",
        { lang_code: language },
        { notThrowError: true }
      );
    }
  } catch (error) {
    console.error("Error saving language:", error);
  }
};
