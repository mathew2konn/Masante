/**
 * types/auth.ts — formes des données d'authentification (Module 2 / 2A.1 côté backend).
 */

export type NiveauCompte = 'base' | 'verifie';

export type Utilisateur = {
  id: number;
  nom: string;
  prenom: string | null;
  telephone: string;
  email: string | null;
  niveau_compte: NiveauCompte;
  telephone_verified_at: string | null;
};

/** Réponse renvoyée après vérification OTP / connexion (délivre le token Bearer). */
export type AuthResponse = {
  token: string;
  token_type: 'Bearer';
  user: Utilisateur;
  message?: string;
};

/** Réponse d'inscription (le compte est créé, OTP envoyé ; en dev le code est exposé). */
export type RegisterResponse = {
  message: string;
  user_id: number;
  but: 'inscription';
  dev_code_otp?: string;
};

/** Étape 1 « mot de passe oublié » : réponse générique (dev_code_otp exposé en dev si le compte existe). */
export type ForgotResponse = {
  message: string;
  dev_code_otp?: string;
};

/** Étape 2 : OTP + preuve validés → jeton de réinitialisation à usage unique. */
export type VerifyResetResponse = {
  message: string;
  reset_token: string;
  expire_dans_minutes: number;
};

/** Réponse simple à message (reset, change). */
export type MessageResponse = {
  message: string;
};
