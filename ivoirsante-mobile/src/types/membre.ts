/**
 * types/membre.ts — formes des données « Membre de la famille » (Module 2 / 2A.2 côté backend).
 *
 * Reflète à l'identique le contrat de l'API (MembreFamille / StoreMembreRequest) :
 *  - `matricule_ivs` et `user_id` ne sont JAMAIS exposés par le serveur (cachés) ;
 *  - `cmu_numero` complet n'est JAMAIS exposé (F2.3) : seul `cmu_numero_masque` (•••• •••• 1234) l'est ;
 *  - `date_naissance` et `cmu_validite` arrivent sérialisés en ISO (cast `date` Laravel).
 */

export type Sexe = 'M' | 'F';

export type GroupeSanguin = 'A+' | 'A-' | 'B+' | 'B-' | 'AB+' | 'AB-' | 'O+' | 'O-';

export type CmuStatut = 'actif' | 'expire' | 'non_inscrit';

/** Membre tel que renvoyé par l'API (clé `membre` / liste `membres`). */
export type Membre = {
  id: number;
  nom: string;
  prenom: string;
  date_naissance: string; // ISO (ex. "1990-01-01T00:00:00.000000Z")
  sexe: Sexe;
  groupe_sanguin: GroupeSanguin | null;
  a_photo: boolean; // le membre a une photo (chargée via l'endpoint dédié ; le chemin interne n'est pas exposé)
  cmu_numero_masque: string | null; // F2.3 — •••• •••• 1234 (le numéro complet ne quitte pas le serveur)
  cmu_statut: CmuStatut | null;
  cmu_validite: string | null;
  created_at?: string;
  updated_at?: string;
};

/** Carte CMU numérique (F2.3) — réponse de `GET /membres/{id}/carte-cmu`. */
export type CarteCmu = {
  titulaire: string;
  cmu_numero_masque: string | null;
  cmu_statut: CmuStatut | null;
  cmu_validite: string | null;
  expiration_proche: boolean;
  disponible: boolean; // palier « vérifié » atteint (stub dev) → carte présentable
  code_presentation: string | null; // contenu du QR CMU signé (null si non présentable)
  code_expire_dans: number | null; // secondes
};

/** Champs acceptés à la création (le matricule est attribué côté serveur). */
export type MembrePayload = {
  nom: string;
  prenom: string;
  date_naissance: string; // format AAAA-MM-JJ envoyé à l'API
  sexe: Sexe;
  groupe_sanguin?: GroupeSanguin | null;
  cmu_numero?: string | null;
  cmu_statut?: CmuStatut | null;
  cmu_validite?: string | null;
};

/** Plafond métier : 15 membres par compte (F2.2 révisé, miroir de StoreMembreRequest::MAX_MEMBRES). */
export const MAX_MEMBRES = 15;

/** Options de groupe sanguin (ordre d'affichage). */
export const GROUPES_SANGUINS: GroupeSanguin[] = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

/** Libellés lisibles du statut CMU. */
export const LIBELLE_CMU_STATUT: Record<CmuStatut, string> = {
  actif: 'Actif',
  expire: 'Expiré',
  non_inscrit: 'Non inscrit',
};
