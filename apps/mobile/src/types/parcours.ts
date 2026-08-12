/**
 * types/parcours.ts — fiche de parcours d'un carnet (incrément D2).
 *
 * TOUT EST DÉJÀ QUALIFIÉ PAR LE SERVEUR. Le regroupement des lignes d'audit en visites, la
 * distinction entre ce qui a été écrit pendant une consultation et ce qui n'y est que rapproché,
 * le libellé d'une voie d'accès : rien de cela ne se recalcule ici (frontière CDC_01 §0.1).
 * L'écran affiche, il ne déduit pas.
 */
import type { TypeAccesDossier } from '@masante/shared';

/** Une entrée du carnet, telle que la fiche la nomme — sans son contenu clinique. */
export type EntreeParcours = {
  section: string;
  id: number;
  a: string | null;
  /** « Ordonnance du Dr Aka Konan » — jamais le nom d'un médicament. */
  libelle: string;
  /** Absent du bloc « autres entrées » : ne concerne que ce qui a été écrit pendant une visite. */
  toujours_au_carnet?: boolean;
};

/** Un passage en établissement, reconstitué depuis le journal d'audit immuable. */
export type VisiteParcours = {
  id: number;
  type: TypeAccesDossier | string;
  /** Libellé citoyen décidé par le serveur (« Accès d'urgence vitale »). */
  type_libelle: string;
  a: string | null;
  agent: string | null;
  /** `null` sur les accès antérieurs à D2 : l'établissement n'était alors pas enregistré. */
  etablissement: string | null;
  /** Faux quand l'agent a fermé son navigateur sans clore la session : la durée est inconnue. */
  cloturee: boolean;
  duree_minutes: number | null;
  /** Justification d'un accès d'urgence vitale — texte libre saisi par l'agent. */
  motif_urgence: string | null;
  sections_consultees: string[];
  /** Lien CERTAIN, attesté par le journal. */
  entrees: EntreeParcours[];
};

/** Une contribution familiale de la période, avec l'état où le serveur la place. */
export type ContributionParcours = {
  id: number;
  section: string;
  statut: 'BROUILLON' | 'VALIDEE' | 'REJETEE';
  auteur: string | null;
  a: string | null;
};

export type FicheParcours = {
  membre: { id: number; prenom: string; nom: string };
  depuis: string;
  visites: VisiteParcours[];
  /**
   * Entrées médicales de la période qu'aucune visite ne réclame. Le lien n'est PAS affirmé —
   * elles sont présentées à part, jamais sous une visite (décision propriétaire du G1).
   */
  autres_entrees: EntreeParcours[];
  contributions: ContributionParcours[];
};
