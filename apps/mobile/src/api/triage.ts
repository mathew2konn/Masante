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
  QuestionsPayload,
  QuestionsResultat,
  SymptomesResponse,
} from '../types/triage';

/** F1.1 — Liste des symptômes actifs, groupés par catégorie. */
export async function getSymptomes(): Promise<SymptomesResponse> {
  const { data } = await api.get<SymptomesResponse>('/v1/symptomes');
  return data;
}

/**
 * P10b-3-i — F1.2 : un tour de questionnaire adaptatif (CDC_08 §4.3b).
 *
 * ═══ POURQUOI PLUSIEURS APPELS PLUTÔT QU'UN SEUL ═══
 *
 * Le serveur ne rend que les questions actuellement débloquées ; y répondre peut en débloquer
 * d'autres. Compiler l'arbre ici l'éviterait, et mettrait une **règle médicale dans le front** —
 * ce que la règle de frontière interdit (CDC_01 §0.1) : « pose cette question si le patient a de
 * la fièvre depuis plus de trois jours » est une décision clinique, pas de l'affichage.
 *
 * Le coût est atténué côté serveur, qui rend TOUT ce qui est déblocable à chaque tour et non une
 * question à la fois : l'arbre converge en quelques allers-retours.
 */
export async function getQuestionsTriage(payload: QuestionsPayload): Promise<QuestionsResultat> {
  const { data } = await api.post<QuestionsResultat>('/v1/triage/questions', payload);
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
