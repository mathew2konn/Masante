import 'server-only';
import { cache } from 'react';
import type { Role, StatutAlerteFraudeIa } from '@masante/shared';
import { getMe } from './session';
import { paiementFetch, type PrincipalPaiement } from './paiement';
import type { AlerteFraude, RapportRoutage } from './fraude-types';

/**
 * Couche serveur des alertes de fraude IA (portail — CDC_05, ADR-020 §B2). Deux responsabilités :
 *  1) GARDE : seul un contrôleur plateforme (admin_ivoirsante ou ministere — décision propriétaire G1)
 *     peut consulter/traiter les alertes. On ne MINTE un principal `ADMIN_FINANCE` qu'après avoir
 *     vérifié ce rôle sur la session Laravel. La garde faisant autorité reste le paiement (principal
 *     signé) ; ce contrôle empêche simplement de minter pour un rôle non habilité (défense en profondeur).
 *  2) PROXY : lectures/actions vers le paiement via `paiementFetch`. Aucune règle métier (CDC_02).
 *
 * Destinataire = contrôleur plateforme INDÉPENDANT (ADR-017), jamais la structure signalée : c'est
 * pourquoi `gestionnaire_etablissement` (établissement-scopé) est exclu. Détection seule : consulter/revue,
 * jamais geler.
 */

const CHEMIN = '/api/v1/fraud-alertes';

/** Rôles habilités à voir l'écran fraude (contrôleurs plateforme indépendants). */
// P11.0 — `super_admin` cède la place à `admin_ivoirsante`. Ce n'est pas un changement de
// politique : ADR-020 §B2 avait choisi `super_admin` FAUTE DE MIEUX, notant lui-même
// qu'`admin_finance` était « absent de l'enum Role ». Or `super_admin` était le doublon dormant
// d'`admin_ivoirsante`, qui porte les 40 permissions du portail. La garde nomme donc le rôle
// survivant, et le contrôleur indépendant qu'exige ADR-017 §7 reste `ministere`.
const ROLES_CONTROLEUR: Role[] = ['admin_ivoirsante', 'ministere'];

/** Rôle porté par le principal minté vers le paiement (le paiement exige exactement celui-ci). */
const ROLE_PAIEMENT = 'ADMIN_FINANCE';

export type ControleurFraude = { sub: string };

/**
 * Le compte connecté est-il un contrôleur plateforme ? Renvoie l'identité (`sub` pour l'audit de
 * revue) ou null. `cache()` dédoublonne au sein d'un rendu.
 */
export const controleurCourant = cache(async (): Promise<ControleurFraude | null> => {
  const user = await getMe();
  if (!user) return null;
  const roles = user.roles ?? [];
  if (!roles.some((r) => ROLES_CONTROLEUR.includes(r))) return null;
  return { sub: `portail:${user.id ?? 'inconnu'}` };
});

function principalDe(c: ControleurFraude): PrincipalPaiement {
  return { sub: c.sub, roles: [ROLE_PAIEMENT] };
}

export type ListeAlertes = { alertes: AlerteFraude[]; interdit: boolean };

/** Liste les alertes (filtre statut optionnel). `interdit` si le compte n'est pas contrôleur. */
export async function getAlertes(statut?: StatutAlerteFraudeIa): Promise<ListeAlertes> {
  const c = await controleurCourant();
  if (!c) return { alertes: [], interdit: true };
  try {
    const res = await paiementFetch('GET', CHEMIN, principalDe(c), {
      query: statut ? `statut=${statut}` : undefined,
    });
    if (!res.ok) return { alertes: [], interdit: res.status === 401 || res.status === 403 };
    return { alertes: (await res.json()) as AlerteFraude[], interdit: false };
  } catch {
    // Paiement injoignable : dégrader en liste vide plutôt que planter la garde serveur.
    return { alertes: [], interdit: false };
  }
}

/** Détail d'une alerte, ou null (introuvable / injoignable / non habilité). */
export async function getAlerte(id: string): Promise<AlerteFraude | null> {
  const c = await controleurCourant();
  if (!c) return null;
  try {
    const res = await paiementFetch('GET', `${CHEMIN}/${id}`, principalDe(c));
    if (!res.ok) return null;
    return (await res.json()) as AlerteFraude;
  } catch {
    return null;
  }
}

export type ResultatAction<T> = { ok: true; data: T } | { ok: false; statut: number };

/** Marque une alerte « revue » (trace humaine — aucune action auto). Attribuée au `sub` du contrôleur. */
export async function marquerRevue(id: string): Promise<ResultatAction<AlerteFraude>> {
  const c = await controleurCourant();
  if (!c) return { ok: false, statut: 403 };
  const res = await paiementFetch('POST', `${CHEMIN}/${id}/revue`, principalDe(c));
  if (!res.ok) return { ok: false, statut: res.status };
  return { ok: true, data: (await res.json()) as AlerteFraude };
}

/** Lance un scan de routage (extrait → score → alerte → notif). Réservé contrôleur plateforme. */
export async function lancerScan(journee?: string): Promise<ResultatAction<RapportRoutage>> {
  const c = await controleurCourant();
  if (!c) return { ok: false, statut: 403 };
  const res = await paiementFetch('POST', `${CHEMIN}/scan`, principalDe(c), {
    query: journee ? `journee=${journee}` : undefined,
  });
  if (!res.ok) return { ok: false, statut: res.status };
  return { ok: true, data: (await res.json()) as RapportRoutage };
}
