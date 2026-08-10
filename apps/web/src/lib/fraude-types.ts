import type { NiveauFraudeIa, StatutAlerteFraudeIa } from '@masante/shared';

/**
 * Types des alertes de fraude IA côté portail. Miroir de `AlerteFraudeReponse` du microservice
 * paiement (CDC_05, ADR-020). Écrits à la main : le paiement n'est pas dans l'OpenAPI Laravel
 * (précédent : `rdv-types.ts`). Les enums `niveau`/`statut` viennent de `@masante/shared` (source
 * unique). Le backend reste l'autorité du niveau/score/statut — le front ne fait qu'AFFICHER.
 */

/** Une règle déterministe déclenchée (snapshot fraud-detection-service). Forme libre selon la règle. */
export type RegleDeclenchee = {
  code?: string;
  libelle?: string;
  [k: string]: unknown;
};

/** Un facteur SHAP (contribution ML explicable — CDC_05 §1.6, jamais de sortie IA sans explication). */
export type FacteurMl = {
  feature?: string;
  contribution?: number;
  [k: string]: unknown;
};

export type AlerteFraude = {
  id: string;
  factureRef: string;
  etablissementRef: string | null;
  patientRef: string | null;
  dateRapport: string;
  niveau: NiveauFraudeIa;
  score: number;
  mode: string;
  statut: StatutAlerteFraudeIa;
  notifiee: boolean;
  regles: RegleDeclenchee[] | null;
  facteurs: FacteurMl[] | null;
  signaux: Record<string, unknown> | null;
  cutOff: string | null;
  createdAt: string | null;
  revueAt: string | null;
  revuePar: string | null;
};

/** Rapport d'un scan de routage (retour de POST /scan). */
export type RapportRoutage = {
  journee: string;
  nbEvaluees: number;
  nbSuspectes: number;
  nbNouvelles: number;
  nbNotifiees: number;
};

/** Libellés d'affichage (web-only, comme `LIBELLE_RDV`). Les valeurs sont les enums partagés. */
export const LIBELLE_NIVEAU: Record<NiveauFraudeIa, string> = {
  NORMAL: 'Normal',
  SUSPECT: 'Suspect',
  TRES_SUSPECT: 'Très suspect',
};

export const LIBELLE_STATUT: Record<StatutAlerteFraudeIa, string> = {
  OUVERTE: 'À traiter',
  REVUE: 'Revue',
};

/** Statuts filtrables (ordre des onglets). `undefined` = toutes. */
export const STATUTS_ALERTE: StatutAlerteFraudeIa[] = ['OUVERTE', 'REVUE'];
