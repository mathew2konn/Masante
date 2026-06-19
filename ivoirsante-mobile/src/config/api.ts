import axios from 'axios';
import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';

/**
 * config/api.ts — CLIENT HTTP UNIQUE de MaSante (§4.6 / §4.7 / §4.8).
 *
 * - L'URL de base vient d'UN seul endroit (app.config.ts -> extra.apiUrl) : quand
 *   l'URL Ngrok change, on ne modifie qu'app.config.ts.
 * - En-têtes par défaut : Accept/Content-Type JSON + ngrok-skip-browser-warning
 *   (évite la page d'avertissement HTML de Ngrok qui casserait le parsing JSON).
 * - Intercepteur : attache automatiquement le token Bearer stocké de façon sécurisée
 *   (expo-secure-store = Keychain/Keystore), jamais en clair.
 */

// Lecture de l'URL de base (source unique). Fallback explicite si non configurée.
export const API_URL: string =
  (Constants.expoConfig?.extra?.apiUrl as string | undefined) ?? '';

export const api = axios.create({
  baseURL: `${API_URL}/api`,
  timeout: 15000,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'ngrok-skip-browser-warning': 'true',
  },
});

// Clé de stockage sécurisé du token d'accès (Keychain iOS / Keystore Android).
const ACCESS_TOKEN_KEY = 'access_token';

/** Lit le token Bearer stocké de façon sécurisée (null si absent). */
export async function getStoredToken(): Promise<string | null> {
  return SecureStore.getItemAsync(ACCESS_TOKEN_KEY);
}

/** Stocke le token Bearer de façon sécurisée (jamais en clair / AsyncStorage). */
export async function saveToken(token: string): Promise<void> {
  await SecureStore.setItemAsync(ACCESS_TOKEN_KEY, token);
}

/** Purge le token (déconnexion / révocation). */
export async function clearToken(): Promise<void> {
  await SecureStore.deleteItemAsync(ACCESS_TOKEN_KEY);
}

// Intercepteur : ajoute le token Bearer si présent (auth Sanctum, révocable au logout).
api.interceptors.request.use(async (config) => {
  const token = await getStoredToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

/**
 * Health-check du socle (§4.10) : vérifie la chaîne app -> Ngrok -> Laravel.
 * Renvoie l'objet JSON { status, service, database, ... } renvoyé par l'API.
 */
export async function checkHealth() {
  const { data } = await api.get('/health');
  return data as {
    status: string;
    service: string;
    environment: string;
    database: string;
    time: string;
  };
}
