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
  /**
   * P6.1 — Identifiant National de Santé (CDC_09 §3). Contrairement au `matricule_ivs`
   * interne (jamais exposé), le NIS est FAIT pour être communiqué : consultations,
   * ordonnances, assurances, CNAM, urgences. `null` tant qu'il n'a pas été attribué.
   */
  nis: string | null;
  pays_code: string | null;
  /** Dossier de santé du titulaire du compte (un seul par compte, hors quota). */
  est_titulaire: boolean;
  /**
   * D2 — ce carnet m'appartient-il, ou m'est-il seulement partagé ?
   *
   * Le serveur cache `user_id` : sans cette réponse, l'application ne pouvait pas distinguer les
   * deux, et proposait à un délégué des actions de gouvernance (journal d'accès brut, gestion des
   * délégués) qui lui renvoyaient un 403. Optionnel : une entrée mise en cache avant D2 ne le
   * porte pas, et l'application se comporte alors comme avant.
   */
  est_proprietaire?: boolean;
  created_at?: string;
  updated_at?: string;
};

/**
 * Champs de la complétion du profil santé du titulaire (P6.1, ADR-021 §2.1).
 * `nom` et `prenom` ne sont PAS envoyés : le serveur les reprend du compte, pour éviter
 * qu'un dossier de santé porte une identité différente de celle du compte.
 */
export type DossierTitulairePayload = {
  date_naissance: string; // AAAA-MM-JJ
  sexe: Sexe;
  groupe_sanguin?: GroupeSanguin | null;
};

/** Réponse de `GET /membres/titulaire` — c'est le BACKEND qui dit si le dossier existe. */
export type EtatDossierTitulaire = {
  existe: boolean;
  membre: Membre | null;
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
  /** P6.8d — l'organisme, lu au référentiel à la lecture (jamais figé sur la couverture). */
  organisme?: string | null;
  organisme_sigle?: string | null;
  /**
   * P6.8d — servie par le serveur, jamais réécrite ici.
   *
   * L'écran annonçait « Il **confirme** votre statut CMU » d'une case remplie par l'intéressé
   * lui-même. La signature prouve que le message vient de MaSanté ; elle ne prouve rien sur le
   * statut, et aucune vérification auprès de la CNAM n'existe dans ce projet.
   *
   * Optionnelle : une carte mise en cache avant P6.8d ne la porte pas.
   */
  mention_provenance?: string;
};

/** Champs acceptés à la création (le matricule est attribué côté serveur). */
export type MembrePayload = {
  nom: string;
  prenom: string;
  date_naissance: string; // format AAAA-MM-JJ envoyé à l'API
  sexe: Sexe;
  groupe_sanguin?: GroupeSanguin | null;
  /**
   * P6.8d — les trois champs `cmu_*` ont disparu de ce contrat d'ÉCRITURE : une couverture santé
   * est un contrat, pas un attribut de la personne, et elle se déclare sur
   * `POST /membres/{id}/couvertures` (voir `types/assurance.ts`). Ils restent exposés en LECTURE
   * sur `Membre`, dérivés de la couverture CNAM.
   */
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
