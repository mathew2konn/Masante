/**
 * api/maladies.ts — Référentiel national des maladies (P6.8c, CDC_09 §8).
 *
 * PUBLIC en lecture, comme les vaccins, les analyses et les médicaments : savoir quelles maladies
 * le pays surveille est une information de santé publique.
 *
 * FRONTIÈRE : cette fonction ne calcule rien et ne devine rien. La recherche est faite par le
 * serveur sur des chaînes normalisées — il ne mesure aucune distance et ne rapproche aucun symptôme
 * d'aucune maladie (CDC_00 §4).
 */
import { api } from '../config/api';
import type { MaladieCatalogue } from '../types/maladie';

/** Recherche au référentiel national : libellé officiel ET libellés alternatifs (« palu »). */
export async function rechercherMaladies(q?: string): Promise<MaladieCatalogue[]> {
  const { data } = await api.get<{ maladies: MaladieCatalogue[] }>('/v1/maladies', {
    params: q ? { q } : undefined,
  });
  return data.maladies;
}
