/**
 * types/delegation.ts — délégation d'accès (voie 3, backend B3), élargie par le carnet familial
 * partagé (incrément A).
 *
 * Dans la liste des délégations, le membre reste projeté a minima (id/prénom/nom) : cette liste
 * gère des DROITS, elle n'est pas une porte d'entrée sur les dossiers. Le carnet lui-même se lit
 * via `GET /membres/partages`, qui applique la Policy côté serveur.
 */
import type { Membre } from './membre';

export type PersonneLite = { id: number; prenom: string | null; nom: string; telephone?: string };
export type MembreLite = { id: number; prenom: string | null; nom: string };

/**
 * Niveaux de droit, du plus faible au plus fort — miroir de `Delegation::DROIT_*` (backend).
 * `qr_generation` = délégations d'avant l'incrément A : elles n'ouvrent aucun dossier.
 * `lecture_ecriture` est réservé à l'incrément C (contributions au brouillon) ; le backend ne
 * l'attribue pas encore.
 */
export type DroitDelegation = 'qr_generation' | 'lecture' | 'lecture_ecriture';

/** Le droit ouvre-t-il le carnet ? Décidé côté serveur ; recopié ici pour l'affichage seul. */
export const OUVRE_LE_CARNET: readonly DroitDelegation[] = ['lecture', 'lecture_ecriture'];

export type Delegation = {
  id: number;
  membre: MembreLite;
  titulaire?: PersonneLite;
  delegue?: PersonneLite;
  droits: DroitDelegation;
  invitee_at: string;
  acceptee_at: string | null;
  revoquee_at: string | null;
};

/** Réponse de `GET /delegations` : accordées (comme titulaire) et reçues (comme délégué). */
export type DelegationsListe = { accordees: Delegation[]; recues: Delegation[] };

/** Un carnet partagé avec moi — réponse de `GET /membres/partages`. */
export type CarnetPartage = {
  delegation_id: number;
  droits: DroitDelegation;
  depuis: string | null;
  partage_par: { prenom: string | null; nom: string | null };
  membre: Membre;
};

/** Réponse de `POST /delegations/en-masse`. */
export type ResultatPartageEnMasse = {
  invitations_creees: number;
  deja_partages: number;
  delegation_ids: number[];
};
