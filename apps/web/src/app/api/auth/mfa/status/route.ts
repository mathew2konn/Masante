import { NextResponse } from 'next/server';
import { authedFetch } from '@/lib/api';

/** Proxy — état MFA du compte connecté. */
export async function GET() {
  const res = await authedFetch('/v1/auth/mfa/status');
  const data = await res.json();
  return NextResponse.json(data, { status: res.status });
}
