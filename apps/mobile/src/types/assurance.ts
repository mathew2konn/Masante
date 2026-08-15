/**
 * types/assurance.ts — Couvertures santé et registre des organismes agréés (P6.8d, CDC_09 §8).
 *
 * FRONTIÈRE : rien n'est calculé ici. Le `statut` d'une couverture, le libellé de sa famille et la
 * mention de provenance arrivent **décidés par le serveur** — l'application les affiche.
 *
 * En particulier, `type_libelle` et la liste `types` viennent de l'API : les recopier ici serait la
 * quatrième récidive du défaut constaté en P6.4b (les communes d'Abidjan en dur côté mobile), et un
 * organisme d'une famille ajoutée demain s'afficherait « undefined ».
 */

/** Les six familles de tiers payant du CDC_06 §8.2. Le LIBELLÉ vient toujours du serveur. */
export type TypeOrganisme =
  | 'cnam'
  | 'assurance'
  | 'mutuelle'
  | 'entreprise'
  | 'ong'
  | 'programme_gouvernemental';

/** Une entrée du registre national — réponse de `GET /v1/assurances`. */
export type OrganismeAssurance = {
  id: number;
  code: string;
  nom: string;
  sigle: string | null;
  type: TypeOrganisme;
  type_libelle: string;
  agrement_statut: 'valide' | 'suspendu' | 'retire' | null;
  agrement_fin: string | null; // AAAA-MM-JJ
  /** Vide dans ce projet : aucun numéro d'agrément réel n'a été chargé, et aucun n'a été inventé. */
  numero_agrement: string | null;
  de_demonstration: boolean;
};

export type RegistreAssurances = {
  organismes: OrganismeAssurance[];
  types: { code: TypeOrganisme; libelle: string }[];
  version: number;
  avertissement: string | null;
  limites: string;
};

/**
 * Statut d'une couverture — **calculé par le serveur** à partir des dates de la ligne.
 *
 * `non_inscrit` n'existe pas : l'absence de couverture se dit par l'absence de ligne (P6.8d).
 */
export type StatutCouverture = 'active' | 'expiree' | 'resiliee';

/** Une couverture déclarée d'un membre. */
export type Couverture = {
  id: number;
  organisme_assurance_id: number | null;
  organisme_code: string | null;
  /** Nom du référentiel quand la couverture y est rattachée, sinon la saisie libre de l'assuré. */
  organisme_nom: string | null;
  organisme_sigle: string | null;
  type: TypeOrganisme | null;
  type_libelle: string | null;
  /** L'organisme n'est pas au registre : la saisie est conservée, MaSanté ne confirme rien. */
  hors_referentiel: boolean;
  numero_masque: string | null;
  date_debut: string | null;
  date_fin: string | null;
  resiliee_le: string | null;
  statut: StatutCouverture;
  provenance: 'declare' | 'verifie';
};

export type ReponseCouvertures = {
  couvertures: Couverture[];
  /** Phrase servie par le serveur — jamais réécrite ici (source unique, cf. `CouvertureMembre`). */
  mention_provenance: string;
};

export type CouverturePayload = {
  organisme_assurance_id?: number | null;
  organisme_libelle?: string | null;
  numero_assure?: string | null;
  date_debut?: string | null;
  date_fin?: string | null;
  resiliee_le?: string | null;
};

/** Libellés d'affichage du statut. Le statut lui-même n'est jamais déduit ici. */
export const LIBELLE_STATUT_COUVERTURE: Record<StatutCouverture, string> = {
  active: 'En cours',
  expiree: 'Expirée',
  resiliee: 'Résiliée',
};
