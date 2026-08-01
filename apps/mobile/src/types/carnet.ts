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

/** Une ligne de médicament d'une ordonnance (medicaments_json). */
export type Medicament = { nom: string; posologie?: string };

/** Un couple paramètre/valeur d'un résultat d'analyse (resultats_json). */
export type ParametreResultat = { parametre: string; valeur: string };

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

export type Champ =
  | ChampTexte
  | ChampDate
  | ChampHeure
  | ChampSelect
  | ChampBooleen
  | ChampMedicaments
  | ChampResultats;

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
