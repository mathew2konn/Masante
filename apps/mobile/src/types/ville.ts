/**
 * types/ville.ts — Villes couvertes et localisation de l'utilisateur (P6.4b).
 *
 * Miroir du contrat backend (`VilleController`). Rien n'est déduit ici : la ville où se trouve
 * l'utilisateur, l'affichage ou non des communes et leur liste sont TOUS calculés côté serveur.
 * C'est la règle de frontière du projet — si le mobile refaisait ce calcul, ouvrir une quatrième
 * ville exigerait de publier une nouvelle version de l'application, et deux versions installées
 * répondraient différemment à la même question.
 */

/** Une ville couverte par la plateforme. */
export interface Ville {
  code: string;
  nom: string;
  latitude: number;
  longitude: number;
  /** Décidé par le SERVEUR : Abidjan oui, Yamoussoukro et Bouaké non. */
  affiche_communes: boolean;
  communes: string[];
}

/** Une ville avec sa distance — sert l'ordre d'affichage quand l'utilisateur est hors zone. */
export interface VilleProche {
  code: string;
  nom: string;
  distance_km: number;
}

/** Catégorie d'établissement et son libellé citoyen, servis par le serveur (source unique). */
export interface TypeEtablissement {
  code: string;
  libelle: string;
}

/** Réponse de `GET /v1/villes`. */
export interface VillesResponse {
  villes: Ville[];
  /**
   * Les 13 catégories et leurs libellés.
   *
   * Elles vivaient en dur dans `structure.ts` (`LIBELLE_TYPE`), avec sept valeurs sur treize :
   * une structure d'une catégorie récente se serait affichée « undefined ». La liste vient
   * désormais du serveur, elle ne peut plus diverger.
   */
  types_etablissement: TypeEtablissement[];
}

/** Réponse de `GET /v1/villes/localiser`. */
export interface Localisation {
  /** `null` quand aucune ville couverte ne contient la position. */
  ville: { code: string; nom: string; affiche_communes: boolean } | null;
  hors_zone: boolean;
  /** Vide si la ville n'affiche pas de communes — l'écran n'a pas à en juger. */
  communes: string[];
  /** Toujours renseigné, ordonné par distance croissante. */
  villes_par_proximite: VilleProche[];
}

/** D'où vient la ville affichée — détermine ce que l'écran a le droit d'affirmer. */
export type SourceVille =
  /** Position réelle du téléphone : « Vous êtes à X ». */
  | 'position'
  /** Choix de l'utilisateur après refus de la localisation : « Ville choisie : X ». */
  | 'choix'
  /** Dernière ville connue, servie hors ligne : « Dernière position connue : X ». */
  | 'memoire';
