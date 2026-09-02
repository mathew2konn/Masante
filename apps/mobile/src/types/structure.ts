/**
 * types/structure.ts — Annuaire géolocalisé des structures sanitaires (Module 3, F3.1→F3.8).
 *
 * Miroir TypeScript du contrat backend (StructureService / StructureController, 3A.1).
 * Données NON sensibles (annuaire public) : aucun chiffrement côté client.
 */

/**
 * Catégorie d'établissement (colonne backend `structures_sanitaires.type`).
 *
 * VOLONTAIREMENT `string` ET NON UNE UNION FERMÉE (P6.4b). L'union listait sept valeurs ; la base
 * en accepte treize depuis P6.4a. Une union fermée ne protégeait de rien — la donnée arrive à
 * l'exécution, TypeScript ne la vérifie pas — mais elle donnait l'illusion d'une garantie et
 * poussait à recopier la liste ici. Les libellés viennent désormais du serveur
 * (`useLocalisation().typesEtablissement`), voir `libelleType()`.
 */
export type TypeStructure = string;

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
  /** B1-b (D5) — numéro national, exposé pour la première fois côté mobile. Transparence patient. */
  numero_professionnel?: string | null;
  /** B1-b (D5) — URL relative, ou absente si le praticien n'a pas déposé de photo. */
  photo_url?: string | null;
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
  /**
   * Images publiques (P6.4c). En LISTE, le serveur n'envoie que le logo ; sur la FICHE, toutes.
   * Déjà ordonnées par le serveur (catégorie puis rang de dépôt) — l'écran ne trie rien.
   */
  images?: ImageEtablissement[];
}

/**
 * Une image d'établissement (P6.4c) — logo ou photo.
 *
 * `url` est **relative** (`/api/v1/structures/12/images/34`). C'est délibéré : une URL absolue
 * serait bâtie sur l'URL Ngrok du moment et deviendrait fausse au prochain redémarrage du tunnel,
 * y compris pour les fiches déjà en cache. Le composant la préfixe avec l'origine de l'API.
 */
export interface ImageEtablissement {
  id: number;
  categorie_code: string;
  mime: string;
  largeur: number | null;
  hauteur: number | null;
  url: string;
}

/** Le code de catégorie du logo, tel que la table de référence backend le nomme. */
export const CATEGORIE_LOGO = 'logo';

/** Filtres acceptés par GET /v1/structures (tous optionnels). */
export interface FiltresStructure {
  type?: TypeStructure;
  /**
   * Code de la ville (`ABJ`, `YAM`, `BKE`) — P6.4b. Un CODE et non un identifiant : c'est ce que
   * renvoie `GET /v1/villes/localiser`, et le mobile n'a pas à connaître les clés primaires.
   */
  ville?: string;
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

/**
 * Signalement de l'historique PUBLIC d'une structure (F3.10).
 *
 * Le serveur ne renvoie que les signalements validés ET publiés par la modération (Module 4.6),
 * et seulement ces quatre champs : jamais l'auteur (anonymat du signalant), ni le motif ni
 * l'identité du modérateur.
 */
export interface SignalementPublic {
  id: number;
  type: TypeSignalement;
  description: string;
  created_at: string;
}

/**
 * Statut d'un rendez-vous (F3.6, enum backend ; validation staff → Module 4, workflow à deux
 * étapes depuis B1-a). Vient de `@masante/shared` — précédent défaut : ce type était dupliqué à
 * la main ici, dans le web, ET dans le PHP, pendant que le VRAI enum partagé (`PREVALIDE_SECRETAIRE`)
 * n'était consommé nulle part.
 */
import type { RendezVousStatut } from '@masante/shared';
export type StatutRdv = RendezVousStatut;

/** Mode d'attribution du médecin (F3.5). `etablissement_attribue` = médecin fixé par l'agent au M4. */
export type ModeAttribution = 'patient_choisit' | 'etablissement_attribue';

/**
 * Rendez-vous tel que renvoyé par GET /v1/rendez-vous (avec relations légères).
 *
 * B1-b — `tarif`/`tarif_source`/`regle` sont un APERÇU calculé à chaque lecture
 * (`RecuRdvService::tarifPour()`), jamais une valeur stockée : ils peuvent changer tant que le
 * RDV n'est pas payé. `triage_id` permet à l'écran de proposer « Associer un triage » seulement
 * quand il est absent (D6).
 */
export interface RendezVous {
  id: number;
  statut: StatutRdv;
  mode_attribution: ModeAttribution;
  motif: string;
  motif_orientation: string | null;
  message_orientation: string | null;
  triage_id: number | null;
  date_souhaitee: string;
  date_confirmee: string | null;
  message_agent: string | null;
  created_at: string;
  membre: { id: number; nom: string; prenom: string } | null;
  structure: { id: number; nom: string; commune: string } | null;
  service: { id: number; nom_service: string; specialite: string } | null;
  medecin: { id: number; titre: string; nom: string; prenom: string; specialite: string; numero_professionnel?: string | null; photo_url?: string | null } | null;
  tarif: number | null;
  tarif_source: 'service' | 'medecin' | 'structure' | null;
  regle: boolean;
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
  /** B1-b (D6) — texte libre et facultatif, distinct du médecin référent. Affichage staff seul. */
  motif_orientation?: string;
  message_orientation?: string;
}

/** Libellés lisibles des types de signalement. */
export const LIBELLE_SIGNALEMENT: Record<TypeSignalement, string> = {
  structure_fermee: 'Structure fermée',
  hors_service: 'Équipement hors service',
  pot_de_vin: 'Demande de pot-de-vin',
  mauvais_traitement: 'Mauvais traitement',
  autre: 'Autre',
};

/** Libellé du statut de RDV — source unique `@masante/shared` (B1-a). */
export { LIBELLE_RDV } from '@masante/shared';

/** Paliers de budget max pour le filtre tarif (F3.2), en FCFA. `valeur: null` = « Tous tarifs ». */
export const BUDGETS: readonly { label: string; valeur: number | null }[] = [
  { label: 'Tous tarifs', valeur: null },
  { label: '≤ 5 000', valeur: 5000 },
  { label: '≤ 10 000', valeur: 10000 },
  { label: '≤ 25 000', valeur: 25000 },
  { label: '≤ 50 000', valeur: 50000 },
];

/*
 * `COMMUNES` a été SUPPRIMÉE en P6.4b.
 *
 * Sept communes d'Abidjan y vivaient en dur. La plateforme couvre désormais trois villes, et
 * seules certaines se subdivisent en communes — une décision qui appartient au serveur
 * (`villes.affiche_communes`). La liste est servie par `GET /v1/villes/localiser`, donc dérivée
 * des structures réellement enregistrées : elle ne peut plus diverger de la base.
 */

/**
 * Libellé citoyen d'une catégorie, à partir de la liste SERVIE PAR LE SERVEUR.
 *
 * Remplace la table `LIBELLE_TYPE` qui vivait ici avec sept entrées sur treize : une structure
 * d'une catégorie récente s'affichait « undefined ». Le repli sur le code brut est délibéré —
 * si une valeur inconnue traverse un jour, l'écran montre quelque chose de lisible plutôt qu'un
 * trou (même principe que `TypesEtablissement::libelle()` côté serveur).
 */
export function libelleType(
  code: string,
  types: readonly { code: string; libelle: string }[],
): string {
  return types.find((t) => t.code === code)?.libelle ?? code;
}
