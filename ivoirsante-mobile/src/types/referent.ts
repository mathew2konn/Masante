/**
 * types/referent.ts — Médecin référent (voie 2 d'accès au dossier, Module 5.6).
 *
 * Désigner un référent ouvre un accès PERMANENT (mais tracé et révocable) au dossier du membre :
 * c'est l'acte le plus engageant du carnet. Reflète `GET|POST|DELETE /v1/membres/{id}/referent`
 * et `GET /v1/medecins` (annuaire public).
 */

/** Fiche de l'annuaire public des praticiens (Module 3 / F3.5). */
export interface Medecin {
  id: number;
  titre: string;
  nom: string;
  prenom: string;
  specialite: string;
  nom_complet: string;
  /**
   * Le praticien a-t-il un compte relié au portail ? Faux = il peut être désigné, mais il ne
   * consultera rien tant que son établissement n'aura pas fait le lien. Le patient doit le savoir
   * AVANT de désigner.
   */
  consulte_en_ligne: boolean;
  structure?: { id: number; nom: string; commune: string } | null;
  service?: { id: number; nom_service: string } | null;
}

/** Une désignation (active si `revoquee_at` est nul). */
export interface Referent {
  id: number;
  membre_id: number;
  medecin_id: number;
  designe_at: string;
  revoquee_at: string | null;
  medecin?: Medecin | null;
}

/** Réponse de `GET /v1/membres/{id}/referent`. */
export interface ReferentVue {
  referent: Referent | null;
  /** Désignations révoquées : la trace que le patient peut opposer (loi n°2013-450). */
  historique: Referent[];
}
