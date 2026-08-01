/**
 * api/mesures.ts — Journal de bord des maladies chroniques (CdC FN5). Client axios unique.
 *
 * Le mobile envoie une VALEUR, jamais un statut : c'est le serveur qui qualifie (normal / bas /
 * élevé / critique) d'après le référentiel de seuils. Pas de mise à jour non plus — une mesure est
 * un fait daté : on la supprime et on la ressaisit.
 */
import { api } from '../config/api';
import { lireAvecCache } from '../services/dossierCache';
import type { JournalMesures, MesureCreee, TypeSaisie } from '../types/mesure';

/** Journal + référentiel des seuils + dernière valeur par type (sur `jours` jours glissants). Lisible hors ligne. */
export async function obtenirJournal(membreId: number, jours = 90): Promise<JournalMesures> {
  return lireAvecCache(`mesures:${membreId}:${jours}`, async () => {
    const { data } = await api.get<JournalMesures>(`/v1/membres/${membreId}/mesures`, {
      params: { jours },
    });
    return data;
  });
}

/** Saisie d'une mesure simple (glycémie, poids, température, pouls, saturation). */
export async function enregistrerMesure(
  membreId: number,
  saisie: { type_mesure: Exclude<TypeSaisie, 'tension'>; valeur: number; date_mesure: string; note?: string },
): Promise<MesureCreee> {
  const { data } = await api.post<MesureCreee>(`/v1/membres/${membreId}/mesures`, saisie);
  return data;
}

/** Saisie d'une tension : UN geste, deux lignes liées côté serveur (systolique + diastolique). */
export async function enregistrerTension(
  membreId: number,
  saisie: { systolique: number; diastolique: number; date_mesure: string; note?: string },
): Promise<MesureCreee> {
  const { data } = await api.post<MesureCreee>(`/v1/membres/${membreId}/mesures`, {
    type_mesure: 'tension',
    ...saisie,
  });
  return data;
}

/** Supprime une saisie erronée. Une tension part entière (ses deux lignes). */
export async function supprimerMesure(membreId: number, mesureId: number): Promise<number> {
  const { data } = await api.delete<{ supprimees: number }>(`/v1/membres/${membreId}/mesures/${mesureId}`);
  return data.supprimees;
}
