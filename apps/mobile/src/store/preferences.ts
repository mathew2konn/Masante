import { create } from 'zustand';
import { i18n } from '@masante/shared';

type ThemeMode = 'light' | 'dark';

/**
 * État global des préférences (Zustand — CDC_01 §3 : langue, thème).
 * L'auth et le verrou restent gérés par les Context existants (SessionContext, VerrouContext).
 */
interface PreferencesState {
  langue: i18n.LangueCode;
  theme: ThemeMode;
  setLangue: (langue: i18n.LangueCode) => void;
  setTheme: (theme: ThemeMode) => void;
}

export const usePreferences = create<PreferencesState>((set) => ({
  langue: i18n.langueParDefaut,
  theme: 'light',
  setLangue: (langue) => set({ langue }),
  setTheme: (theme) => set({ theme }),
}));
