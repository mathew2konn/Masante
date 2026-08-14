/**
 * types/analyse.ts — Catalogue national des analyses (CDC_09 §7.3), P6.7.
 *
 * ═══ CE QUE CES TYPES NE CONTIENNENT PAS ═══
 *
 * Aucun statut, aucun verdict, aucune interprétation. Le serveur ne qualifie pas un résultat de
 * laboratoire, et l'absence de champ le rend impossible côté client aussi : une plage biologique
 * dépend du sexe, de l'âge et parfois de l'état physiologique, et conclure sur une référence unique
 * dirait à une femme enceinte que son hémoglobine est basse alors qu'elle est normale pour elle.
 */

/** Une entrée du catalogue. `code` est nul tant que le backfill n'a pas servi la ligne. */
export interface AnalyseCatalogue {
  id: number;
  code: string | null;
  /** Standard international recommandé par CDC_09 §9.1 — vide tant que le jeu LOINC n'est pas chargé. */
  loinc: string | null;
  libelle: string;
  description?: string | null;
  categorie?: string | null;
  /** Le milieu fait partie de l'IDENTITÉ : deux milieux = deux analyses. */
  milieu_preleve?: string | null;
  unite: string;
  methode?: string | null;
  conditions_prelevement?: string | null;
  conservation?: string | null;
  delai_rendu_heures?: number | null;
  /** « Hémoglobine (Sang veineux) » — ce qui distingue deux entrées de même nom usuel. */
  designation: string;
}

/**
 * Une strate de référence retenue pour ce patient.
 *
 * `conditionnelle` marque une strate qui ne concerne qu'une partie des patients (grossesse) : elle
 * est AJOUTÉE, jamais choisie — la plateforme ne décide pas qu'une patiente est enceinte.
 */
export interface StrateReference {
  libelle_strate: string;
  /** « 12 – 16 », « < 5 », « > 60 » — la plage telle qu'un humain la lit. */
  plage: string;
  valeur_min: number | null;
  valeur_max: number | null;
  etat_physiologique: string;
  etat_libelle: string;
  conditionnelle: boolean;
  retenue_pour: string;
  source: string;
  /** « Valeurs usuelles de démonstration — NON validées cliniquement », le cas échéant. */
  source_libelle: string;
  source_detail?: string | null;
}

/** Réponse de `GET /v1/analyses/{id}/references`. */
export interface ReferencesAnalyse {
  analyse: { code: string | null; libelle: string; unite: string; milieu: string | null };
  references: StrateReference[];
  /** Ce qu'on ne sait pas, DIT plutôt que deviné (âge ou sexe manquants). */
  incertitude: string[];
  /** La version du catalogue qui affirme ces plages — nulle tant qu'aucune n'est publiée. */
  referentiel: { referentiel: string; pays_code: string; version: number } | null;
  /** La phrase qui dit que ce n'est pas un diagnostic. Toujours affichée avec les plages. */
  avertissement: string;
}
