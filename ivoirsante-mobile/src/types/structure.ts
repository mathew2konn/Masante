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

/** Praticien réservable d'un service (F3.5). Annuaire public ; tarif indicatif (aucun paiement). */
export interface Medecin {
  id: number;
  titre: string; // Dr / Pr
  nom: string;
  prenom: string;
  specialite: string;
  tarif_consultation: number | null;
}

/** Service médical d'une structure (avec sa disponibilité du jour et ses médecins réservables). */
export interface Service {
  id: number;
  nom_service: string;
  specialite: string;
  actif: boolean;
  disponibilites: Disponibilite[];
  /** Praticiens réservables actifs (présent sur la fiche détaillée, F3.5). */
  medecins?: Medecin[];
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
  /** Budget max (F3.2) : structures dont la consultation débute à ce tarif ou moins. */
  tarif_max?: number;
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

/** Mode d'attribution du médecin (F3.5). `etablissement_attribue` = médecin fixé par l'agent au M4. */
export type ModeAttribution = 'patient_choisit' | 'etablissement_attribue';

/** Rendez-vous tel que renvoyé par GET /v1/rendez-vous (avec relations légères). */
export interface RendezVous {
  id: number;
  statut: StatutRdv;
  mode_attribution: ModeAttribution;
  motif: string;
  date_souhaitee: string;
  date_confirmee: string | null;
  message_agent: string | null;
  created_at: string;
  membre: { id: number; nom: string; prenom: string } | null;
  structure: { id: number; nom: string; commune: string } | null;
  service: { id: number; nom_service: string; specialite: string } | null;
  medecin: { id: number; titre: string; nom: string; prenom: string; specialite: string } | null;
}

/** Mode de paiement (N1, simulé). */
export type ModePaiement = 'mobile_money' | 'especes' | 'carte';

/** Reçu de RDV présentable (N2) + code de check-in (N3). Aucune donnée médicale dans `code`. */
export interface RecuRdv {
  reference: string;
  statut: string;
  expires_at: string | null;
  patient: string | null;
  structure: { nom: string; commune: string } | null;
  service: string | null;
  medecin: string | null;
  date: string | null;
  montant: number | null;
  mode: ModePaiement | null;
  transaction_ref: string | null;
  /** Contenu du QR de check-in (token signé autonome, n'ouvre pas le dossier). */
  code: string;
  /** Durée de validité du code, en secondes. */
  code_expire_dans: number;
}

/** Libellés lisibles des modes de paiement. */
export const LIBELLE_MODE_PAIEMENT: Record<ModePaiement, string> = {
  mobile_money: 'Mobile Money',
  especes: 'Espèces',
  carte: 'Carte bancaire',
};

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
  /** Médecin choisi (F3.5). Omis = l'établissement attribue. */
  medecin_id?: number;
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

/** Paliers de budget max pour le filtre tarif (F3.2), en FCFA. `valeur: null` = « Tous tarifs ». */
export const BUDGETS: readonly { label: string; valeur: number | null }[] = [
  { label: 'Tous tarifs', valeur: null },
  { label: '≤ 5 000', valeur: 5000 },
  { label: '≤ 10 000', valeur: 10000 },
  { label: '≤ 25 000', valeur: 25000 },
  { label: '≤ 50 000', valeur: 50000 },
];

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
