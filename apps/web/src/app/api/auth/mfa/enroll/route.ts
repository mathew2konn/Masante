import { NextResponse } from 'next/server';
import { authedFetch } from '@/lib/api';

/** Proxy — démarre l'enrôlement TOTP (renvoie secret + otpauth_uri). */
export async function POST() {
  const res = await authedFetch('/v1/auth/mfa/enroll', { method: 'POST' });
  const data = await res.json();
  return NextResponse.json(data, { status: res.status });
}
