/**
 * types/grossesse.ts — Suivi de grossesse (CdC FN4, Module 5.5).
 *
 * Reflète les réponses de `GET|POST|PUT /v1/membres/{id}/grossesse` et de
 * `POST .../grossesse/{id}/consultations`. La semaine d'aménorrhée est CALCULÉE côté serveur
 * (accessor) et livrée telle quelle : le mobile ne la recalcule jamais.
 */

export type StatutGrossesse = 'en_cours' | 'termine' | 'interruption';

/** Une consultation prénatale réalisée (entrée du tableau `consultations_json`, append-only). */
export interface ConsultationPrenatale {
  date: string;
  medecin: string | null;
  structure: string | null;
  notes: string | null;
  /** Horodatage serveur de la saisie (distinct de la date de la CPN). */
  enregistree_le: string;
}

/** Suivi de grossesse d'un membre (en cours ou clôturé). */
export interface SuiviGrossesse {
  id: number;
  membre_id: number;
  date_debut_grossesse: string;
  date_terme_prevue: string;
  consultations_json: ConsultationPrenatale[] | null;
  statut: StatutGrossesse;
  /** Semaine d'aménorrhée en cours (1→43), `null` une fois le suivi clôturé. */
  semaine_actuelle: number | null;
}

/**
 * Un des 8 contacts prénatals OMS/PSN-CI, daté sur la grossesse en cours le cas échéant.
 * `date_estimee` / `passee` valent `null` quand aucune grossesse n'est déclarée (calendrier éducatif).
 */
export interface EtapePrenatale {
  numero: number;
  semaine_recommandee: number;
  libelle: string;
  description: string;
  conseils_nutrition: string;
  date_estimee: string | null;
  passee: boolean | null;
}

/** Réponse de `GET /v1/membres/{id}/grossesse`. */
export interface GrossesseVue {
  suivi: SuiviGrossesse | null;
  historique: SuiviGrossesse[];
  calendrier: EtapePrenatale[];
}
