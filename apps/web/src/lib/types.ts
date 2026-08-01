/**
 * Types d'API — DÉRIVÉS d'OpenAPI (CDC_02). `api-types.ts` est généré par
 * `pnpm gen:api` depuis docs/openapi/masante.openapi.yaml ; on n'écrit rien à la main
 * ici, on ne fait qu'exposer des alias pratiques. Régénérer après toute évolution de l'API.
 */
import type { Role } from '@masante/shared';
import type { components } from './api-types';

export type ApiUser = Omit<components['schemas']['User'], 'roles'> & { roles?: Role[] };
export type AuthResponse = Omit<components['schemas']['AuthResponse'], 'user'> & { user?: ApiUser };
export type MfaChallenge = components['schemas']['MfaChallenge'];
export type MfaStatus = components['schemas']['MfaStatus'];
export type MfaEnroll = {
  type: 'totp';
  secret: string;
  otpauth_uri: string;
};
