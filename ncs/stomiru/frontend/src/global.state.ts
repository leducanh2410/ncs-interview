import { atom, createStore } from "jotai";
import type { AuthData } from "@/features/auth/auth.api";
import { getLanguage, type Language } from "@/configs/user-settings";

export const globalStore = createStore();

export const authAtom = atom<AuthData | null>(null);
export const authUserAtom = atom<AuthData["user"] | undefined>(
  (get) => get(authAtom)?.user
);

export const languageAtom = atom<Language>(getLanguage());