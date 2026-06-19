/**
 * api/triage.ts — appels HTTP du Module 1 (Triage).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (src/config/api.ts) : pas de second client, pas
 * d'URL en dur. Endpoints versionnés sous /v1 (voir routes/api.php côté backend).
 */
import { api } from '../config/api';
import type {
  AnalyserPayload,
  AnalyseResultat,
  FicheResponse,
  HistoriqueResponse,
  SymptomesResponse,
} from '../types/triage';

/** F1.1 — Liste des symptômes actifs, groupés par catégorie. */
export async function getSymptomes(): Promise<SymptomesResponse> {
  const { data } = await api.get<SymptomesResponse>('/v1/symptomes');
  return data;
}

/** F1.3 — Analyse un triage et renvoie le résultat (score, niveau, reco). */
export async function analyserTriage(payload: AnalyserPayload): Promise<AnalyseResultat> {
  const { data } = await api.post<AnalyseResultat>('/v1/triage/analyser', payload);
  return data;
}

/** F1.8 — Fiche partageable (texte prêt pour WhatsApp). */
export async function getFiche(triageId: number): Promise<FicheResponse> {
  const { data } = await api.get<FicheResponse>(`/v1/triage/${triageId}/fiche`);
  return data;
}

/** F1.6 — Historique des triages (récents d'abord). */
export async function getHistorique(membreId?: number): Promise<HistoriqueResponse> {
  const { data } = await api.get<HistoriqueResponse>('/v1/triage/historique', {
    params: membreId ? { membre_id: membreId } : undefined,
  });
  return data;
}
