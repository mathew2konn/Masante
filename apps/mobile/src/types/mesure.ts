/**
 * types/mesure.ts — Journal de bord des maladies chroniques (CdC FN5, Module 5.6).
 *
 * Reflète `GET|POST|DELETE /v1/membres/{id}/mesures`. Le mobile ne connaît AUCUN seuil médical :
 * il reçoit le référentiel du serveur (unités, décimales, bornes, conseils) et l'affiche. Le
 * `statut_norme` est calculé serveur — jamais déduit ici.
 */

/** Les 7 types du CdC §8.3 tels qu'ils sont STOCKÉS (la tension y tient en deux lignes). */
export type TypeMesure =
  | 'glycemie'
  | 'tension_systolique'
  | 'tension_diastolique'
  | 'poids'
  | 'temperature'
  | 'pouls'
  | 'saturation_o2';

/** Type SAISI : la tension se saisit d'un geste (systolique + diastolique) et s'écrit en deux lignes. */
export type TypeSaisie = Exclude<TypeMesure, 'tension_systolique' | 'tension_diastolique'> | 'tension';

export type StatutNorme = 'normal' | 'eleve' | 'bas' | 'critique';

/** Seuils d'un type de mesure (table `referentiels_mesure`, modifiable sans redéployer l'app). */
export interface ReferentielMesure {
  type_mesure: TypeMesure;
  libelle: string;
  unite: string;
  /** Bornes de PLAUSIBILITÉ de saisie (une glycémie à 500 g/L est une faute de frappe). */
  valeur_min: number;
  valeur_max: number;
  normal_min: number;
  normal_max: number;
  critique_bas: number | null;
  critique_haut: number | null;
  decimales: number;
  ordre: number;
  /** Conseil médical affiché quand la valeur sort de la norme (contenu de la base). */
  conseil_anormal: string;
}

/** Une mesure du journal. `groupe_uuid` lie les deux lignes d'une même prise de tension. */
export interface MesureSante {
  id: number;
  membre_id: number;
  groupe_uuid: string | null;
  type_mesure: TypeMesure;
  valeur: number;
  unite: string;
  statut_norme: StatutNorme;
  date_mesure: string;
  note: string | null;
  source: 'patient' | 'medecin' | 'structure';
}

/** Dernière valeur connue d'un type — l'aperçu de tête du journal. */
export interface ResumeMesure {
  type_mesure: TypeMesure;
  libelle: string;
  unite: string;
  valeur: number | null;
  statut_norme: StatutNorme | null;
  date_mesure: string | null;
}

/** Réponse de `GET /v1/membres/{id}/mesures`. */
export interface JournalMesures {
  referentiels: ReferentielMesure[];
  mesures: MesureSante[];
  resume: ResumeMesure[];
  jours: number;
}

/** Réponse de `POST /v1/membres/{id}/mesures` : 1 mesure, ou 2 pour une tension. */
export interface MesureCreee {
  mesures: MesureSante[];
  /** Non nul si au moins une valeur est CRITIQUE : le conseil vient du référentiel serveur. */
  alerte: { statut: 'critique'; conseil: string | null } | null;
}
