import 'server-only';
import { cookies } from 'next/headers';
import { SESSION_COOKIE } from './constants';

/**
 * Accès à l'API Laravel — CÔTÉ SERVEUR uniquement (Route Handlers, Server Components).
 * Proxy pur : aucune règle métier ici (CDC_02 §0.1). Le token Bearer vit dans un cookie
 * httpOnly (jamais exposé au JS client, jamais en localStorage — CDC_02 sécurité).
 */
const API_BASE = `${process.env.API_URL ?? 'http://localhost:8000'}/api`;

export { SESSION_COOKIE };

/** Options du cookie de session : httpOnly + SameSite lax + Secure en production. */
export function cookieOptions() {
  return {
    httpOnly: true,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax' as const,
    path: '/',
    maxAge: 60 * 60 * 24, // 1 jour — aligné sur le TTL du token backend.
  };
}

/** Appel brut à l'API (endpoints publics). */
export function apiFetch(path: string, init?: RequestInit): Promise<Response> {
  return fetch(`${API_BASE}${path}`, {
    ...init,
    headers: { Accept: 'application/json', ...(init?.headers ?? {}) },
    cache: 'no-store',
  });
}

/** Token de session courant (ou null). */
export async function getSessionToken(): Promise<string | null> {
  const jar = await cookies();
  return jar.get(SESSION_COOKIE)?.value ?? null;
}

/** Appel authentifié : ajoute le Bearer depuis le cookie de session. */
export async function authedFetch(path: string, init?: RequestInit): Promise<Response> {
  const token = await getSessionToken();
  return apiFetch(path, {
    ...init,
    headers: {
      ...(init?.headers ?? {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
}
