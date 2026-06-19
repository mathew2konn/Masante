/**
 * api/membres.ts — appels HTTP « Membres de la famille » (backend 2A.2, F2.1).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (src/config/api.ts) : le token Bearer est injecté
 * par l'intercepteur. Endpoints authentifiés sous /v1/membres (isolation anti-IDOR
 * garantie côté serveur par MembreFamillePolicy).
 */
import { api } from '../config/api';
import type { Membre, MembrePayload } from '../types/membre';

/** Liste des membres du compte authentifié (les plus récents d'abord). */
export async function listerMembres(): Promise<Membre[]> {
  const { data } = await api.get<{ membres: Membre[] }>('/v1/membres');
  return data.membres;
}

/** Détail d'un membre (réservé au propriétaire). */
export async function obtenirMembre(id: number): Promise<Membre> {
  const { data } = await api.get<{ membre: Membre }>(`/v1/membres/${id}`);
  return data.membre;
}

/** Création d'un membre. 422 si le plafond de 5 membres est atteint. */
export async function creerMembre(payload: MembrePayload): Promise<Membre> {
  const { data } = await api.post<{ membre: Membre }>('/v1/membres', payload);
  return data.membre;
}

/** Mise à jour partielle d'un membre. */
export async function modifierMembre(id: number, payload: Partial<MembrePayload>): Promise<Membre> {
  const { data } = await api.put<{ membre: Membre }>(`/v1/membres/${id}`, payload);
  return data.membre;
}

/** Suppression d'un membre (réservé au propriétaire). */
export async function supprimerMembre(id: number): Promise<void> {
  await api.delete(`/v1/membres/${id}`);
}
