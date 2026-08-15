/**
 * types/urgence.ts — Module 5 (santé publique & urgences).
 *
 * La fiche vitale est le « sous-ensemble vital minimal » d'un membre (CdC FN2,
 * Note_Continuite §5.2), tel que le renvoie `GET /v1/membres/{id}/fiche-vitale`.
 * Elle ne contient JAMAIS de matricule ni de numéro CMU : elle sert à soigner,
 * pas à identifier administrativement.
 */

export interface MaladieChronique {
  libelle: string;
  /** Traitement en cours : un secouriste doit connaître les interactions possibles. */
  traitement: string | null;
}

export interface VaccinationEssentielle {
  vaccin: string;
  date: string | null;
}

export interface ContactUrgenceVital {
  nom: string;
  lien: string;
  telephone: string;
  principal: boolean;
}

/** Niveau de gravité d'une alerte épidémique (FN3, enum backend). */
export type NiveauAlerte = 'information' | 'vigilance' | 'alerte';

/**
 * Alerte épidémique visible par l'utilisateur (FN3). Le serveur ne renvoie que les alertes en
 * vigueur de sa commune de résidence, plus les alertes nationales.
 */
export interface AlerteEpidemique {
  id: number;
  commune: string;
  titre: string;
  description: string;
  maladie: string;
  niveau_alerte: NiveauAlerte;
  source: string;
  date_debut: string;
  date_fin: string | null;
}

export interface FicheVitale {
  membre_id: number;
  nom: string;
  prenom: string;
  age: number | null;
  sexe: string | null;
  groupe_sanguin: string | null;
  allergies: string[];
  maladies_chroniques: MaladieChronique[];
  vaccinations_essentielles: VaccinationEssentielle[];
  contacts_urgence: ContactUrgenceVital[];
  /** Instant de génération côté serveur : sert à dater le cache hors connexion. */
  genere_le: string;
}

/**
 * P6.8e — Un numéro d'urgence national, tel que le publie le référentiel (CDC_09 §8).
 *
 * `source` est exposée par l'API et transportée jusqu'ici, mais **l'écran SOS ne l'affiche pas** :
 * un avertissement sur la provenance d'un numéro, lu par quelqu'un qui doit appeler des secours,
 * est du bruit au pire moment (décision C1). Elle sert au diagnostic et aux écrans d'exploitation.
 */
export interface NumeroUrgence {
  code: string;
  numero: string;
  libelle: string;
  description: string | null;
  ordre: number;
  source: string | null;
  source_detail: string | null;
}

/** D'où vient la liste actuellement affichée — jamais montré au citoyen, utile aux tests et au debug. */
export type ProvenanceNumeros = 'referentiel' | 'cache' | 'repli';

export interface NumerosUrgenceEtat {
  numeros: NumeroUrgence[];
  provenance: ProvenanceNumeros;
  /** Version du référentiel publiée, si la liste en vient. */
  version: number | null;
}
