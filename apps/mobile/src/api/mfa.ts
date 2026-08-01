/**
 * api/mfa.ts — appels HTTP du second facteur (P1, backend /v1/auth/mfa/*).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (src/config/api.ts). La MFA est « prête à activer » :
 * recommandée au patient, obligatoire pour les rôles professionnels (côté web).
 */
import { api } from '../config/api';
import type { MfaEnroll, MfaStatus } from '../types/mfa';

/** État MFA du compte connecté. */
export async function mfaStatus(): Promise<MfaStatus> {
  const { data } = await api.get<MfaStatus>('/v1/auth/mfa/status');
  return data;
}

/** Démarre l'enrôlement TOTP : renvoie le secret + l'URI otpauth:// (à encoder en QR). */
export async function enrollMfa(): Promise<MfaEnroll> {
  const { data } = await api.post<MfaEnroll>('/v1/auth/mfa/enroll');
  return data;
}

/** Confirme l'enrôlement avec le premier code de l'application d'authentification. */
export async function confirmMfa(code: string): Promise<void> {
  await api.post('/v1/auth/mfa/confirm', { code });
}

/** Désactive le second facteur (retour à l'authentification simple). */
export async function disableMfa(): Promise<void> {
  await api.delete('/v1/auth/mfa');
}
