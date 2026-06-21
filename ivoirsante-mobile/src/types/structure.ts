/**
 * types/structure.ts — Annuaire géolocalisé des structures sanitaires (Module 3, F3.1→F3.8).
 *
 * Miroir TypeScript du contrat backend (StructureService / StructureController, 3A.1).
 * Données NON sensibles (annuaire public) : aucun chiffrement côté client.
 */

/** Type d'établissement (enum backend `structures_sanitaires.type`). */
export type TypeStructure =
  | 'chu'
  | 'chr'
  | 'clinique_privee'
  | 'cabinet'
  | 'pharmacie'
  | 'laboratoire'
  | 'centre_sante';

/** Statut « du jour » agrégé d'une structure (pastille de disponibilité §5.5). */
export type StatutDispo =
  | 'disponible'
  | 'disponible_apres_14h'
  | 'complet'
  | 'ferme'
  | 'inconnu';

/** Position GPS de l'utilisateur (transmise pour le calcul de proximité). */
export type Coordonnees = { lat: number; lng: number };

/** Élément de liste renvoyé par GET /v1/structures (payload léger, sans services). */
export interface Structure {
  id: number;
  nom: string;
  type: TypeStructure;
  adresse: string | null;
  commune: string;
  latitude: number;
  longitude: number;
  telephone: string | null;
  whatsapp: string | null;
  tarif_min_cfa: number | null;
  tarif_max_cfa: number | null;
  note_moyenne: number | null;
  nb_avis: number;
  partenaire_ivoirsante: boolean;
  /** Calculé côté serveur (meilleur statut des services du jour). */
  statut_jour: StatutDispo;
  /** Présent uniquement si une position a été fournie (tri par proximité). */
  distance_km?: number;
}

/** Filtres acceptés par GET /v1/structures (tous optionnels). */
export interface FiltresStructure {
  type?: TypeStructure;
  commune?: string;
  specialite?: string;
  statut?: StatutDispo;
  q?: string;
  lat?: number;
  lng?: number;
  rayon_km?: number;
}

/** Réponse de la liste. */
export interface StructuresResponse {
  structures: Structure[];
}

/** Communes couvertes par le catalogue (seeder 3A.1, district d'Abidjan). */
export const COMMUNES: readonly string[] = [
  'Abobo',
  'Adjamé',
  'Cocody',
  'Marcory',
  'Plateau',
  'Treichville',
  'Yopougon',
] as const;

/** Libellés courts des types (français, pour les filtres et les cartes). */
export const LIBELLE_TYPE: Record<TypeStructure, string> = {
  chu: 'CHU',
  chr: 'CHR',
  clinique_privee: 'Clinique',
  cabinet: 'Cabinet',
  pharmacie: 'Pharmacie',
  laboratoire: 'Laboratoire',
  centre_sante: 'Centre de santé',
};
