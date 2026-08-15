/**
 * types/vaccin.ts — Référentiel des vaccins et calendrier vaccinal national (P6.8b, CDC_09 §8).
 *
 * TOUT CE QUI EST ICI EST CALCULÉ PAR LE SERVEUR. Le statut d'une échéance, l'âge, la date prévue,
 * le caractère obligatoire : le mobile les AFFICHE, il ne les déduit jamais. Test de fin de module
 * (CDC_01 §0.1) : « quelles règles métier ce module calcule-t-il ? » → aucune.
 */
import type { StatutEcheanceVaccinale } from '@masante/shared';

/** Une dose du schéma d'un vaccin, telle que publiée au calendrier national. */
export type DoseVaccin = {
  numero_dose: number;
  libelle_echeance: string | null;
  obligatoire: boolean;
  /** Vrai tant que cette échéance vient du jeu de démonstration, pas d'une autorité. */
  de_demonstration: boolean;
};

/**
 * Un vaccin du référentiel national.
 *
 * `id` est l'identifiant technique, résolu par le serveur : c'est la SEULE clé que le client
 * renvoie pour rattacher une ligne de carnet. `code` est l'identité publiée, celle qu'on affiche.
 */
export type VaccinCatalogue = {
  id: number;
  code: string;
  libelle: string;
  abreviation: string | null;
  maladies_evitees: string | null;
  voie_administration: string | null;
  nb_doses: number;
  statut_marche: string | null;
  doses: DoseVaccin[];
};

/**
 * Une échéance du calendrier, résolue POUR UNE PERSONNE.
 *
 * `statut` distingue `a_venir` (l'enfant est trop jeune — ce n'est pas un retard) et `hors_delai`
 * (la fenêtre de rattrapage publiée est passée). Ces deux valeurs n'existent que pour une échéance,
 * jamais pour une ligne du carnet : les confondre afficherait « en retard » à un nourrisson de cinq
 * semaines pour une dose prévue à six.
 */
export type EcheanceVaccinale = {
  vaccin_code: string;
  vaccin_libelle: string;
  abreviation: string | null;
  maladies_evitees: string | null;
  statut_marche: string | null;
  numero_dose: number;
  nb_doses: number;
  libelle_echeance: string | null;
  obligatoire: boolean;
  age_jours_du: number;
  date_prevue: string;
  statut: StatutEcheanceVaccinale;
  /** L'identifiant de la ligne du carnet qui l'honore, quand elle existe. */
  vaccination_id: number | null;
  de_demonstration: boolean;
};

/** La réponse de `GET /membres/{id}/calendrier-vaccinal`. */
export type CalendrierVaccinal = {
  /** La version du référentiel qui a établi cette réponse (CDC_09 §10). */
  version: number;
  age_jours: number | null;
  /** Renseigné quand le calendrier ne peut pas être établi — il dit ce qui manque. */
  incertitude?: string;
  echeances: EcheanceVaccinale[];
  resume: Record<StatutEcheanceVaccinale, number>;
  /** Combien d'échéances viennent encore du jeu de démonstration. */
  demonstration: number;
  /** La phrase à afficher tant que ce nombre n'est pas nul. Jamais masquée. */
  avertissement: string | null;
};
