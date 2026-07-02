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

/** Disponibilité du jour d'un service (alimente la pastille). */
export interface Disponibilite {
  id: number;
  statut: StatutDispo;
  nb_places_restantes: number | null;
  heure_debut_dispo: string | null;
  note: string | null;
}

/** Service médical d'une structure (avec sa disponibilité du jour). */
export interface Service {
  id: number;
  nom_service: string;
  specialite: string;
  actif: boolean;
  disponibilites: Disponibilite[];
}

/** Avis patient (lecture publique ; auteur anonymisé au prénom). */
export interface Avis {
  id: number;
  note: number;
  commentaire: string | null;
  consultation_verifiee: boolean;
  auteur: string;
  created_at: string;
}

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
  horaires_json: Record<string, string> | null;
  specialites_json: string[] | null;
  tarif_min_cfa: number | null;
  tarif_max_cfa: number | null;
  note_moyenne: number | null;
  nb_avis: number;
  partenaire_ivoirsante: boolean;
  /** Calculé côté serveur (meilleur statut des services du jour). */
  statut_jour: StatutDispo;
  /** Présent uniquement si une position a été fournie (tri par proximité). */
  distance_km?: number;
  /** Présent uniquement sur la fiche détaillée (GET /v1/structures/{id}). */
  services?: Service[];
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

/** Type de signalement citoyen (F3.10, enum backend). */
export type TypeSignalement =
  | 'structure_fermee'
  | 'hors_service'
  | 'pot_de_vin'
  | 'mauvais_traitement'
  | 'autre';

/** Statut d'un rendez-vous (F3.6, enum backend ; validation agent → Module 4). */
export type StatutRdv = 'en_attente' | 'confirme' | 'refuse' | 'annule' | 'honore';

/** Rendez-vous tel que renvoyé par GET /v1/rendez-vous (avec relations légères). */
export interface RendezVous {
  id: number;
  statut: StatutRdv;
  motif: string;
  date_souhaitee: string;
  date_confirmee: string | null;
  message_agent: string | null;
  created_at: string;
  membre: { id: number; nom: string; prenom: string } | null;
  structure: { id: number; nom: string; commune: string } | null;
  service: { id: number; nom_service: string; specialite: string } | null;
}

/** Corps des actions patient (3A.2). */
export interface AvisPayload {
  note: number;
  commentaire?: string;
}
export interface SignalementPayload {
  type: TypeSignalement;
  description: string;
}
export interface RendezVousPayload {
  membre_id: number;
  structure_id: number;
  service_id: number;
  triage_id?: number;
  motif: string;
  date_souhaitee: string; // AAAA-MM-JJ
}

/** Libellés lisibles des types de signalement. */
export const LIBELLE_SIGNALEMENT: Record<TypeSignalement, string> = {
  structure_fermee: 'Structure fermée',
  hors_service: 'Équipement hors service',
  pot_de_vin: 'Demande de pot-de-vin',
  mauvais_traitement: 'Mauvais traitement',
  autre: 'Autre',
};

/** Libellé + couleur sémantique du statut de RDV. */
export const LIBELLE_RDV: Record<StatutRdv, string> = {
  en_attente: 'En attente',
  confirme: 'Confirmé',
  refuse: 'Refusé',
  annule: 'Annulé',
  honore: 'Honoré',
};

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
