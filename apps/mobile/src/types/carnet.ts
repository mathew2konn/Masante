/**
 * types/carnet.ts — sections du carnet de santé (Module 2 / 2A.4 côté backend).
 *
 * Les 5 sections (antécédents, vaccinations, ordonnances, résultats, rappels) partagent le
 * même contrat CRUD : index `{ items }`, store/show/update `{ item }`, destroy `{ message }`.
 * On modélise donc un élément générique + un schéma de champs piloté par le registre, plutôt
 * que 5 écrans dupliqués. Les champs chiffrés au repos (description, medicaments_json…) sont
 * renvoyés EN CLAIR par l'API (cast Eloquent) : aucun traitement spécial côté mobile.
 */

/** Élément générique d'une section (forme libre selon la section). */
export type CarnetItem = {
  id: number;
  created_at?: string;
  [cle: string]: unknown;
};

/**
 * Une ligne d'ordonnance.
 *
 * `nom` reste le texte du prescripteur — un patient qui recopie une ordonnance papier n'a pas de
 * liste sous les yeux, et le lien au référentiel national est donc FACULTATIF.
 *
 * `medicament_id` est la seule clé que le client envoie ; `code_national`, `dci` et
 * `dosage_referentiel` sont posés par le SERVEUR depuis le référentiel et figés à la prescription
 * (P6.6b). Ils sont en lecture seule ici : les déclarer optionnels dit qu'on peut les afficher,
 * jamais qu'on peut les écrire.
 */
export type Medicament = {
  nom: string;
  posologie?: string;
  medicament_id?: number;
  readonly code_national?: string;
  readonly dci?: string;
  readonly dosage_referentiel?: string | null;
};

/**
 * Une ligne de résultat d'analyse.
 *
 * `analyse_id` est la seule clé que le client envoie ; `code_national`, `libelle_catalogue` et
 * `unite_catalogue` sont posés par le SERVEUR depuis le catalogue national et figés à la saisie
 * (P6.7a). Ils sont en lecture seule ici — les déclarer optionnels dit qu'on peut les afficher,
 * jamais qu'on peut les écrire.
 *
 * L'UNITÉ FIGÉE EST LA PLUS IMPORTANTE : une unité qui changerait après coup rendrait le résultat
 * faux d'un facteur 10 ou 100 sans que rien ne le signale.
 */
export type ParametreResultat = {
  parametre: string;
  valeur: string;
  unite?: string;
  analyse_id?: number;
  readonly code_national?: string;
  readonly libelle_catalogue?: string;
  readonly unite_catalogue?: string;
};

/**
 * La saisie d'un vaccin : un nom, et un rattachement FACULTATIF au référentiel national (P6.8b).
 *
 * `nom` reste libre — un parent qui recopie un carnet papier n'a pas la liste sous les yeux, et le
 * référentiel est incomplet : l'imposer ferait de ses lacunes un blocage.
 *
 * `vaccin_id` et `numero_dose` sont les SEULES clés que le client envoie. Le code national et le
 * libellé sont posés par le SERVEUR depuis la version publiée du calendrier, et figés — d'où
 * `readonly` : on peut les afficher, jamais les écrire.
 */
export type SaisieVaccin = {
  nom: string;
  vaccin_id?: number;
  numero_dose?: number;
  readonly code_national?: string;
};

/** Tonalité sémantique d'un badge de liste (mappée aux couleurs du DS). */
export type Ton = 'success' | 'warning' | 'danger' | 'neutre';

/** Résumé d'un élément pour l'affichage en liste. */
export type ResumeItem = {
  titre: string;
  lignes: string[];
  badge?: { texte: string; ton: Ton };
};

// --- Schéma de champ (form engine générique) ---

type ChampBase = {
  cle: string;
  label: string;
  obligatoire?: boolean;
  aide?: string;
};

export type ChampTexte = ChampBase & {
  kind: 'texte';
  multiligne?: boolean;
  max?: number;
  autoCap?: 'none' | 'sentences' | 'words' | 'characters';
  format?: 'telephone' | 'email'; // clavier + validation dédiés (miroir des règles backend)
  defaut?: string; // valeur initiale à la création (ex. indicatif '+225')
};
export type ChampDate = ChampBase & { kind: 'date'; futurInterdit?: boolean; apresChamp?: string };
export type ChampHeure = ChampBase & { kind: 'heure' };
export type ChampSelect = ChampBase & { kind: 'select'; options: { value: string; label: string }[] };
export type ChampBooleen = ChampBase & { kind: 'booleen'; defaut?: boolean };
export type ChampMedicaments = ChampBase & { kind: 'medicaments' };
export type ChampResultats = ChampBase & { kind: 'resultats' };
/**
 * P6.8b — Le vaccin : un nom libre, et un rattachement FACULTATIF au référentiel national.
 *
 * Un champ à part plutôt qu'un `texte` plus un `select` : le nom, le vaccin choisi et la dose ne
 * sont qu'une seule information pour l'utilisateur, et les séparer aurait permis de choisir une
 * dose sans vaccin — donc d'envoyer au serveur une combinaison qu'il refuse.
 */
export type ChampVaccin = ChampBase & { kind: 'vaccin' };
/**
 * P6.8c — La maladie : un rattachement FACULTATIF au référentiel national, à CÔTÉ de la description.
 *
 * À la différence du vaccin, il ne remplace aucun champ existant : `description` reste ce que le
 * patient a écrit, mot pour mot. Le serveur ne devine jamais un code depuis ce texte — ce serait un
 * diagnostic posé par une machine (CDC_00 §4) — donc c'est bien une saisie humaine, séparée.
 */
export type ChampMaladie = ChampBase & { kind: 'maladie' };

export type Champ =
  | ChampTexte
  | ChampDate
  | ChampHeure
  | ChampSelect
  | ChampBooleen
  | ChampMedicaments
  | ChampResultats
  | ChampVaccin
  | ChampMaladie;

/** Descriptif complet d'une section (clé = slug d'URL). */
export type SectionDescriptor = {
  slug: string;
  chemin: string; // segment d'URL côté API (ex. 'resultats-analyses')
  titre: string; // pluriel (ex. « Antécédents »)
  titreSingulier: string; // (ex. « antécédent »)
  icone: string; // nom d'icône Ionicons
  ajoutParPatient?: boolean; // si true, on envoie added_by='patient'
  appendOnly?: boolean; // section append-only : création + suppression seules, pas d'édition (F2.12)
  champs: Champ[];
  resume: (item: CarnetItem) => ResumeItem;
};
