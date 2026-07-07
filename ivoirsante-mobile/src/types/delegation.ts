/**
 * types/delegation.ts — délégation d'accès (voie 3, backend B3).
 * Le membre est projeté a minima (id/prénom/nom) : le délégué ne voit jamais le dossier.
 */

export type PersonneLite = { id: number; prenom: string | null; nom: string; telephone?: string };
export type MembreLite = { id: number; prenom: string | null; nom: string };

export type Delegation = {
  id: number;
  membre: MembreLite;
  titulaire?: PersonneLite;
  delegue?: PersonneLite;
  droits: 'qr_generation';
  invitee_at: string;
  acceptee_at: string | null;
  revoquee_at: string | null;
};

/** Réponse de `GET /delegations` : accordées (comme titulaire) et reçues (comme délégué). */
export type DelegationsListe = { accordees: Delegation[]; recues: Delegation[] };
