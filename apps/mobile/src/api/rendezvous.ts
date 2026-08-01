/**
 * api/rendezvous.ts — Demande et suivi des rendez-vous patient (Module 3, F3.6).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (token Bearer injecté). Routes authentifiées : l'isolation
 * anti-IDOR (§4.3) est garantie côté serveur (RDV des membres du compte uniquement). La
 * validation/confirmation par l'agent relève du Module 4 ; ici le patient ne fait que demander,
 * suivre et annuler.
 */
import { api } from '../config/api';
import type { ModePaiement, RecuRdv, RendezVous, RendezVousPayload } from '../types/structure';

/** F3.6 — Mes rendez-vous (tous les membres du compte), les plus récents d'abord. */
export async function listerRendezVous(): Promise<RendezVous[]> {
  const { data } = await api.get<{ rendez_vous: RendezVous[] }>('/v1/rendez-vous');
  return data.rendez_vous;
}

/** F3.6 — Demande un rendez-vous (créé au statut « en_attente »). */
export async function demanderRendezVous(payload: RendezVousPayload): Promise<RendezVous> {
  const { data } = await api.post<{ rendez_vous: RendezVous }>('/v1/rendez-vous', payload);
  return data.rendez_vous;
}

/** F3.6 — Annule un rendez-vous en attente ou confirmé. */
export async function annulerRendezVous(id: number): Promise<RendezVous> {
  const { data } = await api.patch<{ rendez_vous: RendezVous }>(`/v1/rendez-vous/${id}/annuler`);
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
