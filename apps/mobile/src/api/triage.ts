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

/**
 * F1.8 / §5.4 — Fiche de triage : réponses, hôpitaux proches, QR et mention obligatoire.
 *
 * ═══ LA POSITION EST FACULTATIVE, ET SON ABSENCE N'EST PAS UNE PANNE ═══
 *
 * Sans elle le serveur renvoie les mêmes établissements, simplement non triés par proximité. Un
 * refus de localisation ne doit jamais priver un patient de la liste des hôpitaux qui proposent le
 * service dont il a besoin.
 *
 * Le compte authentifié suffit à lire SA fiche ; `jeton` sert au médecin qui scanne le QR — le
 * mobile n'a pas à le fournir pour lui-même.
 */
export async function getFiche(
  triageId: number,
  position?: { lat: number; lng: number } | null,
): Promise<FicheResponse> {
  const { data } = await api.get<FicheResponse>(`/v1/triage/${triageId}/fiche`, {
    params: position ? { lat: position.lat, lng: position.lng } : undefined,
  });
  return data;
}

/** F1.6 — Historique des triages (récents d'abord). */
export async function getHistorique(membreId?: number): Promise<HistoriqueResponse> {
  const { data } = await api.get<HistoriqueResponse>('/v1/triage/historique', {
    params: membreId ? { membre_id: membreId } : undefined,
  });
  return data;
}
