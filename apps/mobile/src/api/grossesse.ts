/**
 * api/grossesse.ts — Suivi de grossesse (CdC FN4, Module 5.5). Client axios unique (token Bearer).
 *
 * Le mobile ne calcule ni le terme ni la semaine d'aménorrhée : tout vient du serveur. Les
 * consultations s'ajoutent une par une (append-only) — jamais de réécriture du tableau complet.
 */
import { api } from '../config/api';
import { lireAvecCache } from '../services/dossierCache';
import type { ConsultationPrenatale, GrossesseVue, SuiviGrossesse } from '../types/grossesse';

/** Suivi en cours + historique clôturé + calendrier des 8 contacts (renvoyé même sans grossesse). Lisible hors ligne. */
export async function obtenirGrossesse(membreId: number): Promise<GrossesseVue> {
  return lireAvecCache(`grossesse:${membreId}`, async () => {
    const { data } = await api.get<GrossesseVue>(`/v1/membres/${membreId}/grossesse`);
    return data;
  });
}

/** Déclare une grossesse (DDG au format AAAA-MM-JJ). Renvoie le suivi et le nombre de rappels CPN créés. */
export async function declarerGrossesse(
  membreId: number,
  dateDebut: string,
): Promise<{ suivi: SuiviGrossesse; rappels_crees: number }> {
  const { data } = await api.post<{ suivi: SuiviGrossesse; rappels_crees: number }>(
    `/v1/membres/${membreId}/grossesse`,
    { date_debut_grossesse: dateDebut },
  );
  return data;
}

/** Ajuste la date de début (datation échographique) : terme et rappels CPN sont recalculés serveur. */
export async function ajusterDdg(
  membreId: number,
  suiviId: number,
  dateDebut: string,
): Promise<SuiviGrossesse> {
  const { data } = await api.put<{ suivi: SuiviGrossesse }>(
    `/v1/membres/${membreId}/grossesse/${suiviId}`,
    { date_debut_grossesse: dateDebut },
  );
  return data.suivi;
}

/** Clôt le suivi (accouchement ou interruption) : irréversible, le dossier est conservé. */
export async function cloturerGrossesse(
  membreId: number,
  suiviId: number,
  statut: 'termine' | 'interruption',
): Promise<SuiviGrossesse> {
  const { data } = await api.put<{ suivi: SuiviGrossesse }>(
    `/v1/membres/${membreId}/grossesse/${suiviId}`,
    { statut },
  );
  return data.suivi;
}

/** Ajoute une consultation prénatale réalisée (append-only côté serveur). */
export async function ajouterConsultation(
  membreId: number,
  suiviId: number,
  consultation: { date: string; medecin?: string; structure?: string; notes?: string },
): Promise<SuiviGrossesse> {
  const { data } = await api.post<{ suivi: SuiviGrossesse }>(
    `/v1/membres/${membreId}/grossesse/${suiviId}/consultations`,
    consultation,
  );
  return data.suivi;
}

export type { ConsultationPrenatale };
