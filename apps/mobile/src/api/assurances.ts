/**
 * api/assurances.ts — Registre des organismes agréés et couvertures d'un membre (P6.8d, CDC_09 §8).
 *
 * Le registre est PUBLIC en lecture (c'est au moment de créer son dossier qu'on cherche le nom de sa
 * mutuelle) ; les couvertures D'UNE PERSONNE passent par la barrière anti-IDOR du carnet — lecture
 * ouverte au délégué, écriture réservée au propriétaire.
 *
 * FRONTIÈRE : aucune de ces fonctions ne calcule quoi que ce soit. Le statut d'une couverture, le
 * libellé de sa famille et la mention de provenance arrivent déjà décidés par le serveur.
 */
import { api } from '../config/api';
import type {
  CouverturePayload,
  Couverture,
  RegistreAssurances,
  ReponseCouvertures,
  TypeOrganisme,
} from '../types/assurance';

/**
 * Recherche au registre national (nom ou sigle).
 *
 * Répond 503 tant qu'aucune version du référentiel n'est en vigueur : l'appelant traite ce cas en
 * proposant la saisie libre, jamais en affichant une liste vide qui ressemblerait à « aucun
 * organisme n'existe ».
 */
export async function rechercherOrganismes(q?: string, type?: TypeOrganisme): Promise<RegistreAssurances> {
  const { data } = await api.get<RegistreAssurances>('/v1/assurances', {
    params: { ...(q ? { q } : {}), ...(type ? { type } : {}) },
  });
  return data;
}

/** Les couvertures déclarées d'un membre, avec la mention de provenance à afficher. */
export async function listerCouvertures(membreId: number): Promise<ReponseCouvertures> {
  const { data } = await api.get<ReponseCouvertures>(`/v1/membres/${membreId}/couvertures`);
  return data;
}

/** Déclare une couverture. Les avertissements renvoyés (agrément suspendu…) ne bloquent rien. */
export async function ajouterCouverture(
  membreId: number,
  payload: CouverturePayload,
): Promise<{ couverture: Couverture; avertissements: { code: string; message: string }[] }> {
  const { data } = await api.post<{
    couverture: Couverture;
    avertissements: { code: string; message: string }[];
  }>(`/v1/membres/${membreId}/couvertures`, payload);
  return data;
}

export async function modifierCouverture(
  membreId: number,
  couvertureId: number,
  payload: CouverturePayload,
): Promise<{ couverture: Couverture; avertissements: { code: string; message: string }[] }> {
  const { data } = await api.put<{
    couverture: Couverture;
    avertissements: { code: string; message: string }[];
  }>(`/v1/membres/${membreId}/couvertures/${couvertureId}`, payload);
  return data;
}

export async function supprimerCouverture(membreId: number, couvertureId: number): Promise<void> {
  await api.delete(`/v1/membres/${membreId}/couvertures/${couvertureId}`);
}
