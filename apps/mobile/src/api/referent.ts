/**
 * api/referent.ts — Médecin référent (voie 2 d'accès au dossier, Module 5.6). Client axios unique.
 *
 * Le référent se choisit dans l'annuaire PUBLIC des praticiens (le même que celui des rendez-vous) :
 * aucune liste de comptes du portail n'est exposée. La révocation est immédiate et sans condition.
 */
import { api } from '../config/api';
import type { Medecin, Referent, ReferentVue } from '../types/referent';

/** Recherche dans l'annuaire public (par nom, spécialité ou établissement). Endpoint public. */
export async function rechercherMedecins(filtres: {
  q?: string;
  specialite?: string;
  structure_id?: number;
}): Promise<Medecin[]> {
  const { data } = await api.get<{ medecins: Medecin[] }>('/v1/medecins', { params: filtres });
  return data.medecins;
}

/** Référent actif du membre + historique des désignations révoquées. */
export async function obtenirReferent(membreId: number): Promise<ReferentVue> {
  const { data } = await api.get<ReferentVue>(`/v1/membres/${membreId}/referent`);
  return data;
}

/** Désigne (ou remplace) le médecin référent : accès permanent au dossier, tracé et révocable. */
export async function designerReferent(membreId: number, medecinId: number): Promise<Referent> {
  const { data } = await api.post<{ referent: Referent }>(`/v1/membres/${membreId}/referent`, {
    medecin_id: medecinId,
  });
  return data.referent;
}

/** Révoque la désignation : effet immédiat. La ligne reste à l'historique (droit de preuve). */
export async function revoquerReferent(membreId: number, referentId: number): Promise<Referent> {
  const { data } = await api.delete<{ referent: Referent }>(
    `/v1/membres/${membreId}/referent/${referentId}`,
  );
  return data.referent;
}
