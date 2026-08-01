/**
 * types/mfa.ts — formes de l'API MFA (P1, CDC_10 §3.5). Fournies par le backend :
 * l'app AFFICHE ces états et présente le second facteur, elle ne décide de rien.
 */

/** État MFA du compte (le backend est l'autorité — le front ne recalcule jamais). */
export type MfaStatus = {
  enforce: boolean; // exigence MFA globale active (gate serveur)
  oblige_pour_ce_compte: boolean; // le rôle impose la MFA (CDC_10 §3.5) — faux pour un patient
  facteur_confirme: boolean; // un second facteur est confirmé
  doit_configurer: boolean; // obligé mais pas encore configuré
};

/** Données d'enrôlement TOTP (renvoyées une seule fois — le secret ne réapparaît plus ensuite). */
export type MfaEnroll = {
  type: 'totp';
  secret: string;
  otpauth_uri: string; // à encoder en QR pour l'application d'authentification
};
