/**
 * api/alertes.ts — Alertes épidémiques de la commune de l'utilisateur (CdC FN3, Module 5.4).
 *
 * Réutilise le client axios unique. L'endpoint est authentifié et cible la commune de résidence
 * du compte (plus les alertes nationales) : le filtrage se fait côté serveur, le mobile n'a rien
 * à décider.
 */
import { api } from '../config/api';
import type { AlerteEpidemique } from '../types/urgence';

/** Alertes en vigueur pour l'utilisateur, les plus graves d'abord. */
export async function getAlertesEpidemiques(): Promise<AlerteEpidemique[]> {
  const { data } = await api.get<{ alertes: AlerteEpidemique[] }>('/v1/alertes-epidemiques');
  return data.alertes;
}
