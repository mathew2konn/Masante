/**
 * Types d'API — DÉRIVÉS d'OpenAPI (CDC_02). `api-types.ts` est généré par
 * `pnpm gen:api` depuis docs/openapi/masante.openapi.yaml ; on n'écrit rien à la main
 * ici, on ne fait qu'exposer des alias pratiques. Régénérer après toute évolution de l'API.
 */
import type { Role } from '@masante/shared';
import type { components } from './api-types';

/**
 * P11.0 — `permissions` s'ajoute aux rôles. Le backend garde ses routes sur des PERMISSIONS
 * (quatorze n'appartiennent à aucun rôle et sont accordées nominativement) : sans elles, ce
 * portail ne pouvait pas savoir ce qu'un compte atteint réellement. Il s'en sert pour AFFICHER,
 * jamais pour décider — le backend revérifie chaque requête.
 *
 * Le type reste `string[]` et non `Permission[]` : la charge utile vient du réseau, la typer en
 * union ferait passer une valeur inconnue pour une valeur connue à la première divergence. La
 * garde `estPermission` de `@masante/shared` sert quand on a besoin de la certitude.
 */
export type ApiUser = Omit<components['schemas']['User'], 'roles'> & {
  roles?: Role[];
  permissions?: string[];
};
export type AuthResponse = Omit<components['schemas']['AuthResponse'], 'user'> & { user?: ApiUser };
export type MfaChallenge = components['schemas']['MfaChallenge'];
export type MfaStatus = components['schemas']['MfaStatus'];
export type MfaEnroll = {
  type: 'totp';
  secret: string;
  otpauth_uri: string;
};
