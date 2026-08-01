/**
 * api/urgence.ts — Module 5 (urgences). Réutilise le client axios unique (src/config/api.ts).
 *
 * L'endpoint est AUTHENTIFIÉ : seul le titulaire du carnet constitue le cache local de la carte
 * vitale. C'est ce cache, et lui seul, que l'application relit ensuite hors connexion et sans
 * authentification (FN2) — voir `src/urgence/carteVitale.ts`.
 */
import { api } from '../config/api';
import type { FicheVitale } from '../types/urgence';

/** FN2 — Fiche vitale d'un membre (vital minimal). */
export async function getFicheVitale(membreId: number): Promise<FicheVitale> {
  const { data } = await api.get<{ fiche_vitale: FicheVitale }>(`/v1/membres/${membreId}/fiche-vitale`);
  return data.fiche_vitale;
}
