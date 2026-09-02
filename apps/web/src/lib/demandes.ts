import 'server-only';
import { apiFetch, authedFetch } from './api';

/**
 * Accès aux demandes d'inscription (P11.1, CDC_11 §3 méthode 2) — CÔTÉ SERVEUR uniquement.
 *
 * Proxy pur : aucune règle métier (CDC_02 §0.1). La garde `etablissement.manage` fait autorité
 * dans le backend, qui la revérifie à chaque requête ; le registre de zones ne fait qu'éviter
 * d'afficher une page que l'API refusera.
 */

export type StatutDemande = 'en_attente' | 'approuvee' | 'rejetee';

export const STATUTS_DEMANDE: StatutDemande[] = ['en_attente', 'approuvee', 'rejetee'];

export const LIBELLE_STATUT: Record<StatutDemande, string> = {
  en_attente: 'En attente',
  approuvee: 'Approuvée',
  rejetee: 'Rejetée',
};

export type Demande = {
  id: number;
  reference: string;
  nom: string;
  type: string;
  statut_juridique: string | null;
  numero_autorisation: string;
  adresse: string;
  commune: string | null;
  telephone: string;
  email: string;
  demandeur_nom: string;
  demandeur_prenom: string;
  demandeur_fonction: string;
  demandeur_email: string;
  demandeur_telephone: string;
  message: string | null;
  statut: StatutDemande;
  motif_rejet: string | null;
  decide_par_nom: string | null;
  decide_le: string | null;
  structure_id: number | null;
  created_at: string;
};

/** `interdit` distingue « le backend refuse » de « la liste est vide » — deux choses à dire. */
export async function getDemandes(
  statut?: StatutDemande,
): Promise<{ demandes: Demande[]; interdit: boolean }> {
  const query = statut ? `?statut=${statut}` : '';
  const res = await authedFetch(`/v1/portail/demandes-inscription${query}`);

  if (res.status === 403) return { demandes: [], interdit: true };
  if (!res.ok) return { demandes: [], interdit: false };

  const data = (await res.json()) as { demandes?: Demande[] };

  return { demandes: data.demandes ?? [], interdit: false };
}

export async function getDemande(
  id: string,
): Promise<{ demande: Demande | null; interdit: boolean }> {
  const res = await authedFetch(`/v1/portail/demandes-inscription/${id}`);

  if (res.status === 403) return { demande: null, interdit: true };
  if (!res.ok) return { demande: null, interdit: false };

  const data = (await res.json()) as { demande?: Demande };

  return { demande: data.demande ?? null, interdit: false };
}

/** Dépôt public d'une candidature : aucun jeton, c'est le point de la méthode 2. */
export function deposerCandidature(corps: unknown): Promise<Response> {
  return apiFetch('/v1/etablissements/demandes', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(corps),
  });
}
