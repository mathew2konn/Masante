/**
 * api/analyses.ts — Catalogue national des analyses (CDC_09 §7.3), P6.7.
 *
 * Lecture PUBLIQUE : savoir ce qu'est une analyse et dans quelle plage elle se situe habituellement
 * ne demande aucune identité.
 *
 * CE QUE CE CLIENT NE FAIT JAMAIS : comparer un résultat à une plage. Le serveur ne conclut pas, et
 * le mobile encore moins — il affiche la valeur et la référence côte à côte, et laisse juger.
 */
import { api } from '../config/api';
import type { AnalyseCatalogue, ReferencesAnalyse } from '../types/analyse';

/** Recherche au catalogue (code national ou libellé). */
export async function rechercherAnalyses(q?: string): Promise<AnalyseCatalogue[]> {
  const { data } = await api.get<{ analyses: AnalyseCatalogue[] }>('/v1/analyses', {
    params: q ? { q } : undefined,
  });
  return data.analyses;
}

/**
 * Les valeurs de référence d'une analyse pour un patient donné.
 *
 * `ageJours` et `sexe` sont facultatifs : sans eux, le serveur ne renvoie que les strates communes
 * et DIT ce qui manque (`incertitude`). On ne devine rien à sa place.
 */
export async function obtenirReferences(
  analyseId: number,
  ageJours?: number,
  sexe?: 'M' | 'F',
): Promise<ReferencesAnalyse> {
  const { data } = await api.get<ReferencesAnalyse>(`/v1/analyses/${analyseId}/references`, {
    params: { age_jours: ageJours, sexe },
  });
  return data;
}
