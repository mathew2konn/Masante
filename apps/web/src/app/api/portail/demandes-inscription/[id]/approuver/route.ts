import { NextRequest, NextResponse } from 'next/server';
import { authedFetch } from '@/lib/api';

/** Proxy d'approbation (P11.1) — CDC_02 : Route Handler = proxy et session, zéro métier. */
export async function POST(req: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await authedFetch(`/v1/portail/demandes-inscription/${id}/approuver`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(await req.json()),
  });

  return NextResponse.json(await res.json().catch(() => ({})), { status: res.status });
}
