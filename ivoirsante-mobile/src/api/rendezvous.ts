/**
 * api/rendezvous.ts — Demande et suivi des rendez-vous patient (Module 3, F3.6).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (token Bearer injecté). Routes authentifiées : l'isolation
 * anti-IDOR (§4.3) est garantie côté serveur (RDV des membres du compte uniquement). La
 * validation/confirmation par l'agent relève du Module 4 ; ici le patient ne fait que demander,
 * suivre et annuler.
 */
import { api } from '../config/api';
import type { RendezVous, RendezVousPayload } from '../types/structure';

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
