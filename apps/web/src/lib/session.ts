import 'server-only';
import { cache } from 'react';
import { redirect } from 'next/navigation';
import type { Role } from '@masante/shared';
import { authedFetch } from './api';
import type { ApiUser, MfaStatus } from './types';
import { type Zone, zoneAccessible, zoneParChemin, zonesAccessibles } from './zones';

/**
 * Session — lecture serveur du profil et de l'état MFA pour garder les routes (CDC_02).
 * La décision d'accès (rôle, MFA) vient du backend ; on ne fait qu'AFFICHER/rediriger.
 * `cache()` dédoublonne les appels au sein d'un même rendu (layout + page).
 */

/** Rôles considérés « professionnels » : tout sauf le patient (portail réservé — ADR-011). */
const ROLES_PATIENT: Role[] = ['patient'];

export const getMe = cache(async (): Promise<ApiUser | null> => {
  try {
    const res = await authedFetch('/v1/auth/me');
    if (!res.ok) return null;
    const data = (await res.json()) as { user?: ApiUser };
    return data.user ?? null;
  } catch {
    // API injoignable (backend coupé/redémarrage) : on dégrade en « non connecté »
    // plutôt que de planter la garde serveur (« TypeError: fetch failed »).
    return null;
  }
});

export const getMfaStatus = cache(async (): Promise<MfaStatus | null> => {
  try {
    const res = await authedFetch('/v1/auth/mfa/status');
    if (!res.ok) return null;
    return (await res.json()) as MfaStatus;
  } catch {
    return null;
  }
});

/** Le compte a-t-il au moins un rôle professionnel (accès au portail) ? */
export function estProfessionnel(user: ApiUser): boolean {
  const roles = user.roles ?? [];
  return roles.some((r) => !ROLES_PATIENT.includes(r));
}

/**
 * ═══ P11.0 — LA GARDE PAR ZONE, QUI N'EXISTAIT PAS ═══
 *
 * `estProfessionnel` ci-dessus est la porte d'ENTRÉE : elle dit qui est un professionnel, et
 * elle suffisait tant que le portail n'avait que trois modules. Elle ne dit rien de ce qu'on
 * peut atteindre une fois entré — et « tout ce qui n'est pas patient atteint tout » devient un
 * défaut de sécurité avec les onze applications de CDC_11.
 *
 * Les fonctions ci-dessous lisent le registre de zones, seul endroit où est écrit ce qui ouvre
 * quoi. La décision reste au backend, qui revérifie chaque requête ; ici on redirige et on
 * n'affiche que l'atteignable.
 */

/** Permissions du compte connecté (vide si non connecté — jamais `null`, pour simplifier l'appel). */
export async function mesPermissions(): Promise<string[]> {
  const user = await getMe();
  return user?.permissions ?? [];
}

/** Les zones que le compte connecté peut réellement atteindre. */
export async function mesZones(): Promise<Zone[]> {
  const user = await getMe();
  if (!user) return [];
  return zonesAccessibles(user.permissions ?? [], user.roles ?? []);
}

/**
 * Garde d'une page de zone : à appeler en tête de chaque Server Component de zone.
 *
 * Redirige vers l'accueil du portail plutôt que vers `/login` : le compte EST connecté, il n'a
 * simplement pas cette zone. L'envoyer se reconnecter lui ferait croire à une session expirée.
 *
 * Une zone inconnue du registre est refusée, pas laissée passer : le chemin arrive de l'URL, et
 * un registre en liste blanche fermée est ce qui empêche une page d'exister sans garde
 * (précédent `RegistreSectionsCarnet`, P7-C).
 */
export async function exigerZone(chemin: string): Promise<ApiUser> {
  const user = await getMe();
  if (!user) redirect('/login');
  if (!estProfessionnel(user)) redirect('/reserve-pros');

  const zone = zoneParChemin(chemin);
  if (zone === undefined) redirect('/');
  if (!zoneAccessible(zone, user.permissions ?? [], user.roles ?? [])) redirect('/');

  return user;
}
