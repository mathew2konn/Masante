import { NextResponse } from 'next/server';
import { authedFetch, cookieOptions, SESSION_COOKIE } from '@/lib/api';

/** Déconnexion : révoque le token côté serveur (best-effort) puis efface le cookie. */
export async function POST() {
  try {
    await authedFetch('/v1/auth/logout', { method: 'POST' });
  } catch {
    // Même si la révocation échoue (réseau), on déconnecte localement.
  }

  const response = NextResponse.json({ ok: true });
  response.cookies.set(SESSION_COOKIE, '', { ...cookieOptions(), maxAge: 0 });
  return response;
}
