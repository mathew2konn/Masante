/**
 * types/maladie.ts — Référentiel national des maladies (P6.8c, CDC_09 §8).
 *
 * CE MODULE NE CONCLUT RIEN. Il n'associe aucun symptôme à aucune maladie, ne pose aucun
 * diagnostic, ne devine rien depuis un texte : il affiche un vocabulaire servi par le serveur.
 * Test de fin de module (CDC_01 §0.1) : « quelles règles métier ce module calcule-t-il ? » → aucune.
 */

/** Une autre façon de nommer une maladie : autre langue, ou synonyme de recherche (« palu »). */
export type LibelleMaladie = {
  langue: string;
  libelle: string;
  /** Celui qu'on affiche pour cette langue ; les autres ne servent qu'à retrouver. */
  principal: boolean;
};

/**
 * Une maladie du référentiel national.
 *
 * `id` est l'identifiant technique, résolu par le serveur : c'est la SEULE clé que le client
 * renvoie pour rattacher une ligne de carnet. `code` est l'identité publiée, celle qu'on affiche.
 *
 * `code_cim10` / `code_cim11` sont VIDES et le resteront tant que la CIM n'aura pas été chargée :
 * ce sont des publications de l'OMS, aucun code n'a été inventé.
 */
export type MaladieCatalogue = {
  id: number;
  code: string;
  libelle: string;
  code_cim10: string | null;
  code_cim11: string | null;
  description: string | null;
  surveillance_prioritaire: boolean;
  declaration_obligatoire: boolean;
  /** Vrai tant que cette entrée vient du jeu de démonstration, pas d'une autorité. */
  de_demonstration: boolean;
  libelles: LibelleMaladie[];
};

/**
 * La saisie d'un rattachement dans un formulaire de carnet.
 *
 * `recherche` n'est JAMAIS envoyée : elle ne sert qu'à interroger le référentiel. Le libellé et le
 * code affichés viennent du serveur, qui les fige — les renvoyer laisserait croire qu'ils viennent
 * du client, alors qu'ils n'auraient été vérifiés par personne.
 */
export type SaisieMaladie = {
  recherche: string;
  maladie_id?: number;
  libelle?: string;
  code_national?: string;
};
