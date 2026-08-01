import { NextRequest, NextResponse } from 'next/server';
import { authedFetch } from '@/lib/api';

/** Proxy — confirme l'enrôlement avec le premier code de l'application d'authentification. */
export async function POST(req: NextRequest) {
  const body = await req.json();
  const res = await authedFetch('/v1/auth/mfa/confirm', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await res.json();
  return NextResponse.json(data, { status: res.status });
}
