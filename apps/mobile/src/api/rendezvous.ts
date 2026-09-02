/**
 * api/rendezvous.ts — Demande et suivi des rendez-vous patient (Module 3, F3.6).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (token Bearer injecté). Routes authentifiées : l'isolation
 * anti-IDOR (§4.3) est garantie côté serveur (RDV des membres du compte uniquement). La
 * validation/confirmation par l'agent relève du Module 4 ; ici le patient ne fait que demander,
 * suivre et annuler.
 */
import { api } from '../config/api';
import { lireAvecCache } from '../services/dossierCache';
import type { ModePaiement, RecuRdv, RendezVous, RendezVousPayload } from '../types/structure';

/**
 * F3.6 — Mes rendez-vous (tous les membres du compte), les plus récents d'abord. Lisible hors ligne.
 * NB : le REÇU (obtenirRecu) n'est délibérément PAS caché — son `code` de check-in est éphémère
 * (`code_expire_dans`) et un QR périmé présenté à l'accueil serait trompeur.
 */
export async function listerRendezVous(): Promise<RendezVous[]> {
  return lireAvecCache('rendez-vous', async () => {
    const { data } = await api.get<{ rendez_vous: RendezVous[] }>('/v1/rendez-vous');
    return data.rendez_vous;
  });
}

/** F3.6 — Demande un rendez-vous (créé au statut « en_attente »). */
export async function demanderRendezVous(payload: RendezVousPayload): Promise<RendezVous> {
  const { data } = await api.post<{ rendez_vous: RendezVous }>('/v1/rendez-vous', payload);
  return data.rendez_vous;
}

/** F3.6 — Annule un rendez-vous en attente, pré-validé ou confirmé (B1-a). */
export async function annulerRendezVous(id: number): Promise<RendezVous> {
  const { data } = await api.patch<{ rendez_vous: RendezVous }>(`/v1/rendez-vous/${id}/annuler`);
  return data.rendez_vous;
}

/** B1-b (D6) — Associe un triage après coup (le lien existe déjà en base, `store()` le pose à la
 * création ; ceci le pose plus tard, mêmes vérifications anti-IDOR). */
export async function associerTriage(id: number, triageId: number): Promise<RendezVous> {
  const { data } = await api.patch<{ rendez_vous: RendezVous }>(`/v1/rendez-vous/${id}/triage`, {
    triage_id: triageId,
  });
  return data.rendez_vous;
}

/** N1/N2 — Paie (simulé) un RDV et récupère son reçu + QR de check-in. */
export async function payerRendezVous(id: number, mode: ModePaiement): Promise<RecuRdv> {
  const { data } = await api.post<{ recu: RecuRdv }>(`/v1/rendez-vous/${id}/paiement`, { mode });
  return data.recu;
}

/** N2/N3 — Reçu d'un RDV (404 si non encore payé). Le `code` est régénéré à chaque appel. */
export async function obtenirRecu(id: number): Promise<RecuRdv> {
  const { data } = await api.get<{ recu: RecuRdv }>(`/v1/rendez-vous/${id}/recu`);
  return data.recu;
}
