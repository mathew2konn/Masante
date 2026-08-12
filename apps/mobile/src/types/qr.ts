/**
 * types/qr.ts — QR dynamique de partage + journal d'accès (Module 2 / 2A.3 côté backend).
 *
 * Le QR encode un identifiant OPAQUE (jamais le matricule, §5.2 Sécurité), à usage unique
 * et valable 10 minutes. Le journal d'accès est IMMUABLE (§10, loi 2013-450).
 */

/** Réponse de génération d'un QR : contenu à encoder + délai d'expiration (en secondes). */
export type QrGenere = {
  qr: string;
  expires_in: number;
};

/** Une entrée du journal d'audit des accès au dossier d'un membre. */
export type AccesDossier = {
  id: number;
  membre_id: number;
  agent_id: number | null;
  token_qr_id: number | null;
  type_acces: string;
  etablissement: string | null;
  sections_consultees: string[] | null;
  donnees_ajoutees: Record<string, unknown> | null;
  ip_address: string | null;
  duree_minutes: number | null;
  created_at: string;
};

/**
 * Libellés des voies d'accès — réexportés depuis `@masante/shared` (incrément D2).
 *
 * ILS VIVAIENT ICI, EN DUR, ET AVAIENT DIVERGÉ DE LA BASE. Trois des cinq voies réelles
 * (`referent`, `delegation`, `bris_de_glace`) n'y figuraient pas, et deux libellés présents
 * (`consultation`, `ajout`) ne correspondaient à aucune valeur existante. Résultat : un parent
 * lisait « bris_de_glace » — valeur brute, tiret bas compris — dans le journal d'accès de son
 * enfant. C'est le constat F1 du G0 de D2.
 *
 * La table est désormais dans `@masante/shared`, avec son miroir PHP `App\Support\TypeAccesDossier`.
 * Ce fichier ne la redéfinit plus : il la réexporte, pour que les écrans existants n'aient pas à
 * changer leur import.
 */
export { libelleTypeAcces, LIBELLE_TYPE_ACCES } from '@masante/shared';
