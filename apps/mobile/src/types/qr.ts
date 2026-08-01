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
  sections_consultees: string[] | null;
  donnees_ajoutees: Record<string, unknown> | null;
  ip_address: string | null;
  duree_minutes: number | null;
  created_at: string;
};

/** Libellés lisibles des types d'accès connus (repli sur la valeur brute sinon). */
export const LIBELLE_TYPE_ACCES: Record<string, string> = {
  qr_scan: 'Consultation par QR',
  consultation: 'Consultation du dossier',
  ajout: 'Ajout au dossier',
};

/** Rend lisible un type d'accès (repli sur la valeur brute si inconnue). */
export function libelleTypeAcces(type: string): string {
  return LIBELLE_TYPE_ACCES[type] ?? type;
}
