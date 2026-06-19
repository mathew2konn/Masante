/**
 * api/auth.ts — appels HTTP d'authentification (backend 2A.1 : téléphone + OTP + Sanctum).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (src/config/api.ts). Endpoints sous /v1/auth.
 */
import { api } from '../config/api';
import type { AuthResponse, RegisterResponse, Utilisateur } from '../types/auth';

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

/** Profil de l'utilisateur authentifié (token requis). */
export async function me(): Promise<Utilisateur> {
  const { data } = await api.get<{ user: Utilisateur }>('/v1/auth/me');
  return data.user;
}

/** Déconnexion : révoque le token courant côté serveur. */
export async function logout(): Promise<void> {
  await api.post('/v1/auth/logout');
}
