import { create } from 'zustand';

/**
 * État réseau partagé — signale quand une lecture a été servie depuis le CACHE local faute de
 * connexion (le bandeau « hors ligne » de l'incrément 2.2 s'y abonne). Posé par dossierCache.
 */
type ReseauState = {
  horsLigne: boolean;
  /** Date (ms) de la donnée servie depuis le cache, pour afficher « données du … ». */
  majCache: number | null;
  marquerHorsLigne: (maj: number) => void;
  marquerEnLigne: () => void;
};

export const useReseau = create<ReseauState>((set) => ({
  horsLigne: false,
  majCache: null,
  marquerHorsLigne: (maj) => set({ horsLigne: true, majCache: maj }),
  marquerEnLigne: () => set((e) => (e.horsLigne ? { horsLigne: false, majCache: null } : e)),
}));
