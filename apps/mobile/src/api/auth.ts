/**
 * api/auth.ts — appels HTTP d'authentification (backend 2A.1 : téléphone + OTP + Sanctum).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (src/config/api.ts). Endpoints sous /v1/auth.
 */
import { api } from '../config/api';
import { lireAvecCache } from '../services/dossierCache';
import type {
  AuthResponse,
  ForgotResponse,
  MessageResponse,
  RegisterResponse,
  Utilisateur,
  VerifyResetResponse,
} from '../types/auth';

/** Inscription : crée le compte (non vérifié) et déclenche l'envoi d'un OTP. */
export async function register(payload: {
  telephone: string;
  nom: string;
  prenom: string;
  password: string;
}): Promise<RegisterResponse> {
  const { data } = await api.post<RegisterResponse>('/v1/auth/register', {
    ...payload,
    password_confirmation: payload.password,
  });
  return data;
}

/** Renvoi d'un code OTP. */
export async function resendOtp(telephone: string): Promise<{ message: string; dev_code_otp?: string }> {
  const { data } = await api.post('/v1/auth/resend-otp', { telephone, but: 'inscription' });
  return data;
}

/** Vérifie le code OTP : active le compte « base » et délivre un token Bearer. */
export async function verifyOtp(payload: { telephone: string; code: string }): Promise<AuthResponse> {
  const { data } = await api.post<AuthResponse>('/v1/auth/verify-otp', {
    ...payload,
    but: 'inscription',
  });
  return data;
}

/** Connexion par téléphone + mot de passe (compte déjà vérifié). */
export async function login(payload: { telephone: string; password: string }): Promise<AuthResponse> {
  const { data } = await api.post<AuthResponse>('/v1/auth/login', payload);
  return data;
}

/**
 * Profil de l'utilisateur authentifié (token requis). Lisible hors ligne (cache chiffré) : au
 * redémarrage sans réseau, on rend le profil mémorisé plutôt que d'invalider la session. Un token
 * réellement invalide renvoie un 401 (réponse serveur) → non caché, la session est bien nettoyée.
 */
export async function me(): Promise<Utilisateur> {
  return lireAvecCache('me', async () => {
    const { data } = await api.get<{ user: Utilisateur }>('/v1/auth/me');
    return data.user;
  });
}

/** Déconnexion : révoque le token courant côté serveur. */
export async function logout(): Promise<void> {
  await api.post('/v1/auth/logout');
}

/* ------------------------------------------------------------------ *
 *  Mot de passe oublié (flux OTP 3 étapes durci) + changement connecté.
 * ------------------------------------------------------------------ */

/** Étape 1 — demande de réinitialisation par téléphone (réponse générique côté serveur). */
export async function passwordForgot(telephone: string): Promise<ForgotResponse> {
  const { data } = await api.post<ForgotResponse>('/v1/auth/password/forgot', { telephone });
  return data;
}

/** Étape 2 — OTP + preuve durcie (date de naissance) → jeton de réinitialisation. */
export async function passwordVerifyOtp(payload: {
  telephone: string;
  code: string;
  date_naissance?: string | null;
}): Promise<VerifyResetResponse> {
  const { data } = await api.post<VerifyResetResponse>('/v1/auth/password/verify-otp', {
    telephone: payload.telephone,
    code: payload.code,
    ...(payload.date_naissance ? { date_naissance: payload.date_naissance } : {}),
  });
  return data;
}

/** Étape 3 — définition du nouveau mot de passe via le jeton (révoque toutes les sessions). */
export async function passwordReset(payload: { reset_token: string; password: string }): Promise<MessageResponse> {
  const { data } = await api.post<MessageResponse>('/v1/auth/password/reset', {
    reset_token: payload.reset_token,
    password: payload.password,
    password_confirmation: payload.password,
  });
  return data;
}

/** Changement volontaire par l'utilisateur connecté (ancien + nouveau, révoque les autres sessions). */
export async function passwordChange(payload: {
  current_password: string;
  password: string;
}): Promise<MessageResponse> {
  const { data } = await api.post<MessageResponse>('/v1/auth/password/change', {
    current_password: payload.current_password,
    password: payload.password,
    password_confirmation: payload.password,
  });
  return data;
}
