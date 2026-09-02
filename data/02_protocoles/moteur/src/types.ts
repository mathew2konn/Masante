/**
 * Types du moteur de protocoles MaSanté.
 * Rule-002 : ce code ne dépend d'aucun framework. Il ne connaît ni Laravel, ni React, ni PostgreSQL.
 */

export type Valeur = string | number | boolean | string[] | null | undefined;

/** Les données cliniques d'entrée, telles que fournies par l'appelant. */
export type ContextePatient = Record<string, Valeur>;

export interface VariableEntree {
  cle: string;
  type: 'entier' | 'decimal' | 'booleen' | 'enum' | 'liste' | 'texte';
  unite?: string;
  valeurs?: string[];
  referentiel?: string;
  obligatoire: boolean;
}

export interface Action {
  type: string;
  valeur?: string;
  ref?: string;
  message?: string;
  medicament?: string;
  posologie?: string;
  maximum?: string;
  modalite?: string;
  motif?: string;
  exclure?: string[];
}

export interface Regle {
  id: string;
  priorite: number;
  libelle: string;
  condition: string;
  actions: Action[];
  niveau_preuve?: string;
  annotation?: string;
  /** Identifiants de règles dont les actions sont annulées si celle-ci se déclenche. */
  remplace?: string[];
}

export interface TranchePosologie {
  age?: string;
  poids_min?: number | null;
  poids_max?: number | null;
  presentation?: string;
  dose: string;
  prises_par_jour?: number;
  duree_jours?: number;
}

export interface Protocole {
  id: string;
  version: string;
  titre: string;
  pays_applicable: string[];
  statut: string;
  cycle_de_vie: { etat: string; [k: string]: unknown };
  source: Record<string, string>;
  variables_entree: VariableEntree[];
  referentiels_internes?: Record<string, unknown>;
  garde_fous?: Record<string, unknown>;
  regles: Regle[];
  traitements: Record<string, any>;
  arbres_decision?: Record<string, any>;
  suivi?: unknown;
  tracabilite?: { champs_obligatoires_journal: string[] };
}

export interface RegleDeclenchee {
  regle_id: string;
  libelle: string;
  priorite: number;
  niveau_preuve?: string;
  actions: Action[];
  annulee_par?: string;
}

export interface Recommandation {
  action: string;
  detail?: string;
  justification: string;
  niveau_preuve?: string;
  protocole: { id: string; version: string };
  regle_id: string;
}

export interface ResultatEvaluation {
  trace_id: string;
  protocole: { id: string; version: string; etat: string };
  classification: string | null;
  recommandations: Recommandation[];
  posologie: PosologieResolue | null;
  alertes: string[];
  contre_indications: { medicament: string; motif?: string }[];
  variables_manquantes: string[];
  variables_optionnelles_absentes: string[];
  regles_declenchees: RegleDeclenchee[];
  conflits: string[];
  duree_ms: number;
}

export interface PosologieResolue {
  traitement: string;
  option_code: string;
  option_libelle: string;
  presentation?: string;
  dose: string;
  prises_par_jour?: number;
  duree_jours?: number;
  tranche: string;
}
