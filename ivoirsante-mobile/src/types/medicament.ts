/**
 * types/medicament.ts — Comparateur de prix (FN7) et ruptures (FN8), Module 5.8.
 *
 * Le mobile n'invente aucun prix et ne juge aucune plausibilité : il affiche ce que le serveur a
 * retenu (médiane des relevés récents, ou déclaration du pharmacien), avec sa provenance et sa
 * fraîcheur. Un prix affiché sans sa date et sa source serait une affirmation ; ici, c'est un constat.
 */
import type { Structure } from './structure';

export interface Medicament {
  id: number;
  /** DCI — c'est elle qui rapproche une marque de son générique. */
  nom_generique: string;
  nom_commercial: string | null;
  categorie: string;
  prix_reference_cfa: number | null;
  ordonnance_requise: boolean;
  disponible_generique: boolean;
  /** « Doliprane (Paracétamol 500 mg) » — ce que le patient reconnaît en rayon. */
  libelle: string;
}

/** D'où vient le prix affiché : le pharmacien lui-même, ou l'agrégat des patients. */
export type SourcePrix = 'cename' | 'pharmacie_portail' | 'crowdsource_patient';

/** Ce qu'on sait d'une pharmacie pour un médicament donné. */
export interface OffrePharmacie {
  structure: Pick<Structure, 'id' | 'nom' | 'commune' | 'latitude' | 'longitude'> & {
    adresse?: string;
    telephone?: string | null;
  };
  prix_cfa: number | null;
  source: SourcePrix | null;
  disponible: boolean;
  /** Nombre de relevés de patients derrière la médiane (0 si le prix vient du pharmacien). */
  releves: number;
  date_mise_a_jour: string | null;
}

/** Réponse de `GET /v1/medicaments/{id}/prix`. */
export interface ComparateurVue {
  medicament: Medicament;
  offres: OffrePharmacie[];
  /** FN7 — mêmes molécules moins chères (rapprochement par DCI, sur le prix officiel). */
  generiques: Medicament[];
}

/** FN8 — une rupture agrégée : ce médicament manque dans N pharmacies. */
export interface RuptureAgregee {
  medicament: Medicament;
  pharmacies: Pick<Structure, 'id' | 'nom' | 'commune'>[];
  nb_pharmacies: number;
  depuis: string | null;
}

/** Réponse de `POST /v1/recus/lecture` : l'OCR PROPOSE, il ne décide pas. */
export interface LectureRecu {
  montants: number[];
  /** Texte brut lu : le patient voit d'où sort le montant proposé, et peut corriger. */
  texte: string;
}
