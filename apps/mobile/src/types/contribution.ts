/**
 * types/contribution.ts — brouillons du carnet familial partagé (incrément C).
 *
 * Une contribution est une PROPOSITION auto-déclarée d'un délégué, en attente de validation par
 * un responsable. Elle n'est jamais un acte médical : ce qu'un médecin écrit ne passe pas par ici.
 */
import type { MembreLite, PersonneLite } from './delegation';

/** Miroir de `Contribution::BROUILLON|VALIDEE|REJETEE` (backend). */
export type StatutContribution = 'BROUILLON' | 'VALIDEE' | 'REJETEE';

export const LIBELLE_STATUT: Record<StatutContribution, string> = {
  BROUILLON: 'En attente de validation',
  VALIDEE: 'Validée',
  REJETEE: 'Rejetée',
};

export type Contribution = {
  id: number;
  membre_id: number;
  section: string;
  donnees: Record<string, unknown>;
  statut: StatutContribution;
  motif_rejet: string | null;
  decide_le: string | null;
  created_at: string;
  auteur?: PersonneLite | null;
  membre?: MembreLite | null;
};

/** Une désignation de responsable — qui décide avec moi, ou pour qui je décide. */
export type Responsable = {
  id: number;
  titulaire_user_id: number;
  responsable_user_id: number;
  designe_le: string;
  revoque_le: string | null;
  responsable?: PersonneLite;
  titulaire?: PersonneLite;
};
