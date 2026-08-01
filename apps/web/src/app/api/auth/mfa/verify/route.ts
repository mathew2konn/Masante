import { NextRequest, NextResponse } from 'next/server';
import { apiFetch, cookieOptions, SESSION_COOKIE } from '@/lib/api';

/**
 * Proxy 2e étape de connexion : échange le jeton de défi + code TOTP contre une session.
 * Sur succès, on pose le token dans le cookie httpOnly (jamais renvoyé au client).
 */
export async function POST(req: NextRequest) {
  const body = await req.json();

  const res = await apiFetch('/v1/auth/mfa/verify', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await res.json();

  if (!res.ok) {
    return NextResponse.json(data, { status: res.status });
  }

  const response = NextResponse.json({ ok: true, user: data.user });
  response.cookies.set(SESSION_COOKIE, data.token, cookieOptions());
  return response;
}
