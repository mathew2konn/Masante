import 'server-only';
import { cache } from 'react';
import type { Role } from '@masante/shared';
import { authedFetch } from './api';
import type { ApiUser, MfaStatus } from './types';

/**
 * Session — lecture serveur du profil et de l'état MFA pour garder les routes (CDC_02).
 * La décision d'accès (rôle, MFA) vient du backend ; on ne fait qu'AFFICHER/rediriger.
 * `cache()` dédoublonne les appels au sein d'un même rendu (layout + page).
 */

/** Rôles considérés « professionnels » : tout sauf le patient (portail réservé — ADR-011). */
const ROLES_PATIENT: Role[] = ['patient'];

export const getMe = cache(async (): Promise<ApiUser | null> => {
  const res = await authedFetch('/v1/auth/me');
  if (!res.ok) return null;
  const data = (await res.json()) as { user?: ApiUser };
  return data.user ?? null;
});

export const getMfaStatus = cache(async (): Promise<MfaStatus | null> => {
  const res = await authedFetch('/v1/auth/mfa/status');
  if (!res.ok) return null;
  return (await res.json()) as MfaStatus;
});

/** Le compte a-t-il au moins un rôle professionnel (accès au portail) ? */
export function estProfessionnel(user: ApiUser): boolean {
  const roles = user.roles ?? [];
  return roles.some((r) => !ROLES_PATIENT.includes(r));
}
