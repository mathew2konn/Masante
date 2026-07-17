/**
 * api/medicaments.ts — Comparateur de prix (FN7) et ruptures (FN8). Client axios unique.
 *
 * Lire est PUBLIC (savoir où trouver un médicament ne demande aucune identité) ; SIGNALER exige un
 * compte (un relevé anonyme ne se conteste pas, et un comparateur ouvert à l'anonymat s'empoisonne).
 */
import { api } from '../config/api';
import type { ComparateurVue, LectureRecu, Medicament, RuptureAgregee } from '../types/medicament';

/** Recherche au catalogue (DCI ou nom commercial). */
export async function rechercherMedicaments(q?: string): Promise<Medicament[]> {
  const { data } = await api.get<{ medicaments: Medicament[] }>('/v1/medicaments', {
    params: q ? { q } : undefined,
  });
  return data.medicaments;
}

/** Le comparateur d'un médicament : prix par pharmacie (moins cher d'abord) + génériques moins chers. */
export async function comparerPrix(medicamentId: number, commune?: string): Promise<ComparateurVue> {
  const { data } = await api.get<ComparateurVue>(`/v1/medicaments/${medicamentId}/prix`, {
    params: commune ? { commune } : undefined,
  });
  return data;
}

/** FN8 — Vue agrégée des ruptures du moment (« éviter les déplacements inutiles »). */
export async function obtenirRuptures(commune?: string): Promise<RuptureAgregee[]> {
  const { data } = await api.get<{ ruptures: RuptureAgregee[] }>('/v1/ruptures', {
    params: commune ? { commune } : undefined,
  });
  return data.ruptures;
}

/** Rapporte un prix payé (crowdsourcing). Le serveur refuse les montants invraisemblables. */
export async function releverPrix(
  medicamentId: number,
  structureId: number,
  prixCfa: number,
): Promise<void> {
  await api.post(`/v1/medicaments/${medicamentId}/prix`, {
    structure_id: structureId,
    prix_cfa: prixCfa,
  });
}

/** FN8 — Signale une rupture (« je l'ai cherché, il n'y en avait pas »). */
export async function signalerRupture(medicamentId: number, structureId: number): Promise<void> {
  await api.post(`/v1/medicaments/${medicamentId}/rupture`, { structure_id: structureId });
}

/**
 * FN7 « scan de reçu » — envoie la photo du ticket et récupère les montants LUS.
 *
 * L'OCR tourne sur NOTRE serveur (Tesseract auto-hébergé) : un reçu de pharmacie est une donnée de
 * santé, il ne part pas chez un tiers. La photo est détruite dès la lecture faite : elle n'est ni
 * stockée sur le téléphone, ni conservée par l'API. Et elle ne crée AUCUN relevé — le patient
 * choisit le montant, le corrige au besoin, puis le soumet lui-même via `releverPrix`.
 */
export async function lireRecu(uri: string): Promise<LectureRecu> {
  const formData = new FormData();
  const extension = uri.split('.').pop()?.toLowerCase() === 'png' ? 'png' : 'jpg';

  formData.append('recu', {
    uri,
    name: `recu.${extension}`,
    type: `image/${extension === 'png' ? 'png' : 'jpeg'}`,
  } as unknown as Blob);

  const { data } = await api.post<LectureRecu>('/v1/recus/lecture', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });

  return data;
}
