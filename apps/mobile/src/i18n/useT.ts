import { i18n } from '@masante/shared';
import { usePreferences } from '../store/preferences';

/**
 * Hook de traduction — renvoie le dictionnaire de la langue active (source unique @masante/shared).
 * Usage : `const t = useT(); t.auth.connexion`.
 */
export function useT() {
  const langue = usePreferences((s) => s.langue);
  return i18n.langues[langue];
}
