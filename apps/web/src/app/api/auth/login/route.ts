import { NextRequest, NextResponse } from 'next/server';
import { apiFetch, cookieOptions, SESSION_COOKIE } from '@/lib/api';

/**
 * Proxy de connexion (CDC_02 : Route Handler = proxy + session, zéro métier).
 * Si le backend exige un second facteur, on renvoie le défi SANS ouvrir de session.
 * Sinon on pose le token dans un cookie httpOnly et on ne le renvoie jamais au client.
 */
export async function POST(req: NextRequest) {
  const body = await req.json();

  const res = await apiFetch('/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await res.json();

  if (!res.ok) {
    return NextResponse.json(data, { status: res.status });
  }

  // Défi MFA : pas encore de session, le client enchaîne sur /api/auth/mfa/verify.
  if (data.mfa_required) {
    return NextResponse.json({ mfa_required: true, mfa_token: data.mfa_token });
  }

  const response = NextResponse.json({ ok: true, user: data.user });
  response.cookies.set(SESSION_COOKIE, data.token, cookieOptions());
  return response;
}
