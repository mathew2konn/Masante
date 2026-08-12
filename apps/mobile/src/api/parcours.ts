/**
 * api/parcours.ts — fiche de parcours d'un carnet (incrément D2).
 *
 * Réutilise le client axios unique (token Bearer injecté). Une seule opération : lire. La fiche
 * n'a aucune action — on n'y valide rien, on n'y corrige rien. La décision sur une contribution
 * reste sur son propre écran, réservé aux responsables.
 */
import { api } from '../config/api';
import type { FicheParcours } from '../types/parcours';

/**
 * Fiche de parcours d'un membre.
 *
 * La profondeur d'historique est décidée par le serveur (donnée de configuration). `depuis` permet
 * de remonter plus loin ; on ne le calcule jamais ici pour « compléter » une fenêtre par défaut.
 */
export async function chargerParcours(membreId: number, depuis?: string): Promise<FicheParcours> {
  const { data } = await api.get<FicheParcours>(`/v1/membres/${membreId}/parcours`, {
    params: depuis ? { depuis } : undefined,
  });
  return data;
}
