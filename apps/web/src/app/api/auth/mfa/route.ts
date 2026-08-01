import { NextResponse } from 'next/server';
import { authedFetch } from '@/lib/api';

/** Proxy — désactive le second facteur du compte connecté. */
export async function DELETE() {
  const res = await authedFetch('/v1/auth/mfa', { method: 'DELETE' });
  const data = await res.json();
  return NextResponse.json(data, { status: res.status });
}
